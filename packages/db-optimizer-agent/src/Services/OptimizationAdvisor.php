<?php

namespace Mdj\DbOptimizer\Services;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Arr;

class OptimizationAdvisor
{
    /**
     * @param  array<string, mixed>  $metric
     * @return array<int, array<string, mixed>>
     */
    public function recommend(QueryExecuted $event, array $metric): array
    {
        if (! (bool) config('db_optimizer.advanced_suggestions', true)) {
            return [];
        }

        $recommendations = [];
        $rawSql          = (string) ($metric['raw_sql'] ?? $event->sql);
        $normalizedSql   = $this->normalizeSql($event->sql);
        $sourceCurrent   = trim((string) Arr::get($metric, 'source_code.current', ''));

        // ── N+1 detection ──────────────────────────────────────────────────────
        $nPlusOneDetected  = (bool) Arr::get($metric, 'detectors.n_plus_one.is_suspected', false);
        $repetition        = (int)  Arr::get($metric, 'detectors.n_plus_one.repetition', 1);

        if ($nPlusOneDetected) {
            $recommendations[] = $this->buildNPlusOneRecommendation($event->sql, $rawSql, $sourceCurrent, $repetition, $metric);
        }

        // Only run other checks when this query is NOT an N+1 repeated fetch
        if (! $nPlusOneDetected) {
            $currentLaravel = $sourceCurrent !== ''
                ? $sourceCurrent
                : $this->buildLaravelBuilderFromSql($rawSql);

            // ── SELECT * ──────────────────────────────────────────────────────
            if (str_starts_with($normalizedSql, 'select') && str_contains($normalizedSql, 'select *')) {
                $optimized = preg_replace('/\bselect\s+\*/i', 'SELECT id /* add required columns */', $rawSql) ?? $rawSql;
                $recommendations[] = $this->make(
                    'Select only needed columns',
                    'Avoid SELECT * to reduce IO and memory.',
                    $rawSql,
                    $optimized,
                    92,
                    true,
                    currentLaravel: $currentLaravel,
                    optimizedLaravel: $this->optimizeSelectAllLaravel($currentLaravel),
                );
            }

            // ── EXISTS (SELECT *) ──────────────────────────────────────────────
            if ((bool) preg_match('/\bexists\s*\(\s*select\s+\*/i', $normalizedSql)) {
                $optimized = preg_replace('/\bexists\s*\(\s*select\s+\*/i', 'EXISTS (SELECT 1', $rawSql) ?? $rawSql;
                $recommendations[] = $this->make(
                    'Use SELECT 1 inside EXISTS',
                    'EXISTS checks row presence only; selecting columns is unnecessary.',
                    $rawSql,
                    $optimized,
                    86,
                    true,
                    currentLaravel: $currentLaravel,
                    optimizedLaravel: str_contains($currentLaravel, '->exists()')
                        ? $currentLaravel
                        : "// Prefer exists() for presence checks\n".$currentLaravel,
                );
            }

            // ── COUNT → EXISTS ─────────────────────────────────────────────────
            if (str_starts_with($normalizedSql, 'select count(')) {
                $optimized = $this->rewriteCountToExists($rawSql);
                $recommendations[] = $this->make(
                    'Use EXISTS instead of COUNT when checking presence',
                    'For boolean checks, EXISTS can stop at first match and is usually cheaper.',
                    $rawSql,
                    $optimized,
                    88,
                    true,
                    currentLaravel: $currentLaravel,
                    optimizedLaravel: $this->rewriteLaravelCountToExists($currentLaravel),
                );
            }

            // ── Missing indexes ────────────────────────────────────────────────
            if (! empty(Arr::get($metric, 'detectors.missing_indexes', []))) {
                $first  = Arr::first((array) Arr::get($metric, 'detectors.missing_indexes', []));
                $table  = is_array($first) ? (string) ($first['table']  ?? 'table_name')  : 'table_name';
                $column = is_array($first) ? (string) ($first['column'] ?? 'column_name') : 'column_name';

                if ($table !== '' && $column !== '' && strtolower($column) !== 'id') {
                    $idxName    = $this->safeIndexName($table, $column);
                    $guardedSql = $this->buildGuardedIndexSql($table, $column, $idxName);

                    $recommendations[] = $this->make(
                        'Add index for filter/join column',
                        "Missing leading index on `{$table}`.`{$column}`. Run the guarded SQL below; it skips safely if the index already exists.",
                        $rawSql,
                        "ALTER TABLE `{$table}` ADD INDEX `{$idxName}` (`{$column}`);",
                        95,
                        false,
                        executableSql: $guardedSql,
                        currentLaravel: $currentLaravel,
                        optimizedLaravel: "// Keep the application query as-is; add the index in MySQL.\n// Run the 'Executable SQL (Safe/Guarded)' block below in your DB client or migration.",
                    );
                }
            }

            // ── Cache candidate ────────────────────────────────────────────────
            if ((bool) Arr::get($metric, 'detectors.cache_candidate.is_candidate', false)) {
                $recommendations[] = $this->make(
                    'Cache repeated read query',
                    'Static repeated SELECT detected with identical bindings.',
                    $rawSql,
                    "Cache::remember('db-opt-key', 300, fn () => DB::select(\"{$this->escapeForPhpString($event->sql)}\"));",
                    84,
                    true,
                    currentLaravel: $currentLaravel,
                    optimizedLaravel: "Cache::remember('db-opt-key', 300, fn () =>\n    {$currentLaravel}\n);",
                );
            }

            // ── OFFSET pagination ──────────────────────────────────────────────
            if (str_contains($normalizedSql, ' offset ')) {
                $recommendations[] = $this->make(
                    'Prefer keyset pagination over OFFSET',
                    'Large OFFSET values degrade performance on big datasets.',
                    $rawSql,
                    'SELECT ... WHERE id < :last_seen_id ORDER BY id DESC LIMIT 12',
                    76,
                    false,
                    currentLaravel: $currentLaravel,
                    optimizedLaravel: "// Keyset pagination example\nDB::table('table_name')\n    ->where('id', '<', \$lastSeenId)\n    ->orderByDesc('id')\n    ->limit(12)\n    ->get();",
                );
            }

            // ── Slow query EXPLAIN hint ────────────────────────────────────────
            if (isset($metric['explain'])) {
                $recommendations[] = $this->make(
                    'Tune query based on EXPLAIN',
                    'Slow query plan indicates scan/sort bottlenecks. '.(string)($metric['explain']['summary'] ?? ''),
                    $rawSql,
                    '-- add/selective indexes on WHERE + JOIN columns; avoid filesort/temporary --',
                    93,
                    false,
                    currentLaravel: $currentLaravel,
                    optimizedLaravel: null,
                );
            }
        }

        foreach ($recommendations as &$recommendation) {
            if ((bool) config('db_optimizer.auto_apply_safe_optimizations', false) && ($recommendation['safe_auto_apply'] ?? false)) {
                $recommendation['auto_apply_eligible'] = true;
                $recommendation['auto_applied']        = false;
            }
        }
        unset($recommendation);

        usort($recommendations, static fn (array $a, array $b): int => ((int) ($b['priority'] ?? 0)) <=> ((int) ($a['priority'] ?? 0)));

        return array_slice($recommendations, 0, max(1, (int) config('db_optimizer.recommendation_limit', 8)));
    }

    // ──────────────────────────────────────────────────────────────────────────
    // N+1 recommendation builder
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Build a meaningful N+1 recommendation.
     * Shows:  [Current]   – the actual repeated SQL being fired
     *         [Optimized] – a concrete eager-loading rewrite
     *
     * @param  array<string, mixed>  $metric
     * @return array<string, mixed>
     */
    private function buildNPlusOneRecommendation(
        string $sql,
        string $rawSql,
        string $sourceCurrent,
        int $repetition,
        array $metric,
    ): array {
        // Extract table and relation hint from the repeated SQL
        $table    = $this->extractTableFromSql($sql);
        $relation = $this->guessRelationName($sql, $table);
        $idColumn = $this->extractWhereIdColumn($sql);

        // ── Current: show the actual repeated query ───────────────────────────
        $currentSqlDisplay = $rawSql;

        // ── Current Laravel: show source if available, else meaningful pseudo-code
        if ($sourceCurrent !== '') {
            $currentLaravel = $sourceCurrent;
        } else {
            $currentLaravel = $this->buildNPlusOneCurrentLaravel($table, $relation, $idColumn, $repetition);
        }

        // ── Optimized Laravel: proper eager loading ───────────────────────────
        $optimizedLaravel = $this->buildNPlusOneOptimizedLaravel($sourceCurrent, $table, $relation);

        // ── Optimized SQL: single JOIN-based query ─────────────────────────────
        $optimizedSql = $this->buildNPlusOneOptimizedSql($sql, $table, $relation, $idColumn);

        $description = sprintf(
            'This query ran %d times in this request — typical N+1 pattern. '
            .'Instead of fetching `%s` rows one by one inside a loop, load all related records at once using eager loading.',
            $repetition,
            $table ?: 'related',
        );

        return $this->make(
            'Resolve N+1 via eager loading',
            $description,
            $currentSqlDisplay,
            $optimizedSql,
            120,
            false,
            currentLaravel: $currentLaravel,
            optimizedLaravel: $optimizedLaravel,
        );
    }

    /**
     * Build a "current" Laravel pseudo-code block showing the N+1 pattern.
     */
    private function buildNPlusOneCurrentLaravel(
        string $table,
        string $relation,
        string $idColumn,
        int $repetition,
    ): string {
        $model      = $this->tableToModelName($table);
        $parentModel = 'Post'; // generic parent guess

        return <<<PHP
// ⚠ N+1 detected — query ran {$repetition}× in this request
\$items = {$parentModel}::all(); // loads N parent records

foreach (\$items as \$item) {
    \$item->{$relation}; // fires a new SELECT every iteration!
    // SELECT * FROM `{$table}` WHERE `{$idColumn}` = ?
}
PHP;
    }

    /**
     * Build an optimized Laravel eager-loading block.
     */
    private function buildNPlusOneOptimizedLaravel(
        string $sourceCurrent,
        string $table,
        string $relation,
    ): string {
        $model      = $this->tableToModelName($table);
        $parentModel = 'Post';

        // If we have real source code, try to inject ->with()
        if ($sourceCurrent !== '') {
            $rewritten = $this->injectEagerLoading($sourceCurrent, $relation);
            if ($rewritten !== $sourceCurrent) {
                return "// ✅ Optimized: eager load `{$relation}` to avoid N+1\n".$rewritten;
            }
        }

        return <<<PHP
// ✅ Optimized: load all related `{$table}` records in ONE query
\$items = {$parentModel}::with('{$relation}')->get();

foreach (\$items as \$item) {
    \$item->{$relation}; // already loaded — no extra query fired
}
PHP;
    }

    /**
     * Build a single optimized SQL for N+1 (JOIN-based).
     */
    private function buildNPlusOneOptimizedSql(
        string $sql,
        string $table,
        string $relation,
        string $idColumn,
    ): string {
        // Try to extract parent table from WHERE column e.g. post_id → posts
        $parentTable = $this->guessParentTable($idColumn);

        if ($parentTable && $table) {
            return "-- Single query replaces N+{1} repeated queries\n"
                ."SELECT `{$parentTable}`.*, `{$table}`.*\n"
                ."FROM `{$parentTable}`\n"
                ."LEFT JOIN `{$table}` ON `{$table}`.`{$idColumn}` = `{$parentTable}`.`id`\n"
                ."WHERE `{$parentTable}`.`id` IN (/* your parent IDs */);";
        }

        if ($table) {
            return "-- Load all related records at once\n"
                ."SELECT * FROM `{$table}`\n"
                ."WHERE `{$idColumn}` IN (/* parent IDs from first query */);";
        }

        return '-- Use eager loading: load related records in a single WHERE IN query';
    }

    // ──────────────────────────────────────────────────────────────────────────
    // SQL helpers
    // ──────────────────────────────────────────────────────────────────────────

    private function extractTableFromSql(string $sql): string
    {
        if (preg_match('/\bfrom\s+`?([a-zA-Z_][\w]*)`?/i', $sql, $m)) {
            return $m[1];
        }

        return '';
    }

    private function extractWhereIdColumn(string $sql): string
    {
        // e.g. WHERE `post_id` = ? or WHERE id = ?
        if (preg_match('/\bwhere\b.+?`?([a-zA-Z_][\w]*(?:_id|id))`?\s*=\s*\?/i', $sql, $m)) {
            return $m[1];
        }

        return 'id';
    }

    private function guessRelationName(string $sql, string $table): string
    {
        // Derive from WHERE column: post_id → post (singular relation)
        $idCol = $this->extractWhereIdColumn($sql);

        if ($idCol !== 'id' && str_ends_with(strtolower($idCol), '_id')) {
            return substr($idCol, 0, -3); // strip _id
        }

        // Fall back to table name singular
        return $table ? rtrim($table, 's') : 'relation';
    }

    private function guessParentTable(string $idColumn): string
    {
        // post_id → posts
        if (str_ends_with(strtolower($idColumn), '_id')) {
            return substr($idColumn, 0, -3).'s';
        }

        return '';
    }

    private function tableToModelName(string $table): string
    {
        // users → User, blog_posts → BlogPost
        $parts = explode('_', $table);
        $parts = array_map('ucfirst', $parts);
        $name  = implode('', $parts);

        // Naive singular: strip trailing 's' if present
        return rtrim($name, 's') ?: 'Model';
    }

    /**
     * Inject ->with('relation') into real source code if possible.
     */
    private function injectEagerLoading(string $source, string $relation): string
    {
        // Already has eager loading
        if (str_contains($source, '->with(')) {
            return $source;
        }

        foreach (['->get()', '->first()', '->paginate('] as $terminal) {
            if (str_contains($source, $terminal)) {
                $withCode = "->with('{$relation}')";

                return str_replace($terminal, $withCode.$terminal, $source);
            }
        }

        return $source;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Helpers shared across recommendations
    // ──────────────────────────────────────────────────────────────────────────

    private function normalizeSql(string $sql): string
    {
        return preg_replace('/\s+/', ' ', strtolower(trim($sql))) ?? strtolower(trim($sql));
    }

    private function rewriteCountToExists(string $sql): string
    {
        $normalized = strtolower($sql);
        $fromPos    = strpos($normalized, ' from ');

        if ($fromPos === false) {
            return 'SELECT EXISTS(SELECT 1 FROM your_table WHERE ...) AS exists_flag';
        }

        $tail = substr($sql, $fromPos + 6);

        return 'SELECT EXISTS(SELECT 1 FROM '.$tail.' LIMIT 1) AS exists_flag';
    }

    /**
     * @return array<string, mixed>
     */
    private function make(
        string $title,
        string $description,
        string $currentSql,
        string $optimizedSql,
        int $priority,
        bool $safeAutoApply,
        ?string $executableSql = null,
        ?string $currentLaravel = null,
        ?string $optimizedLaravel = null,
    ): array {
        return [
            'title'              => $title,
            'description'        => $description,
            'current_sql'        => $currentSql,
            'optimized_sql'      => $optimizedSql,
            'executable_sql'     => $executableSql,
            'current_laravel'    => $currentLaravel,
            'optimized_laravel'  => $optimizedLaravel,
            'priority'           => $priority,
            'safe_auto_apply'    => $safeAutoApply,
            'auto_apply_eligible'=> false,
        ];
    }

    private function safeIndexName(string $table, string $column): string
    {
        return 'idx_'.preg_replace('/[^a-zA-Z0-9_]+/', '_', $table.'_'.$column);
    }

    private function buildGuardedIndexSql(string $table, string $column, string $indexName): string
    {
        $tableEsc  = str_replace("'", "''", $table);
        $columnEsc = str_replace("'", "''", $column);

        return <<<SQL
-- Safe to run multiple times on MySQL
SET @dbopt_idx_exists := (
    SELECT COUNT(1)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = '{$tableEsc}'
      AND column_name = '{$columnEsc}'
      AND seq_in_index = 1
);

SET @dbopt_stmt := IF(
    @dbopt_idx_exists = 0,
    'ALTER TABLE `{$table}` ADD INDEX `{$indexName}` (`{$column}`)',
    'SELECT "skip: index already exists for {$table}.{$column}"'
);

PREPARE dbopt_query FROM @dbopt_stmt;
EXECUTE dbopt_query;
DEALLOCATE PREPARE dbopt_query;
SQL;
    }

    private function optimizeSelectAllLaravel(string $currentLaravel): string
    {
        if ($currentLaravel === '') {
            return $currentLaravel;
        }

        if (str_contains($currentLaravel, "->select('*')")) {
            return str_replace("->select('*')", "->select(['id']) // add required columns", $currentLaravel);
        }

        if (! str_contains($currentLaravel, '->select(')) {
            if (str_contains($currentLaravel, '->first()')) {
                return str_replace('->first()', "->select(['id']) // add required columns\n    ->first()", $currentLaravel);
            }

            if (str_contains($currentLaravel, '->paginate(')) {
                return preg_replace('/->paginate\(([^\)]*)\)/', "->select(['id']) // add required columns\n    ->paginate($1)", $currentLaravel) ?? $currentLaravel;
            }

            if (str_contains($currentLaravel, '->get()')) {
                return str_replace('->get()', "->select(['id']) // add required columns\n    ->get()", $currentLaravel);
            }
        }

        return $currentLaravel;
    }

    private function rewriteLaravelCountToExists(string $currentLaravel): string
    {
        if ($currentLaravel === '') {
            return $currentLaravel;
        }

        if (str_contains($currentLaravel, '->count()')) {
            return str_replace('->count();', '->exists();', $currentLaravel);
        }

        return "// Use exists() if checking only presence\n".$currentLaravel;
    }

    private function buildLaravelBuilderFromSql(string $sql): string
    {
        $flatSql = preg_replace('/\s+/', ' ', trim($sql)) ?? trim($sql);

        if (! str_starts_with(strtolower($flatSql), 'select ')) {
            return 'DB::statement("'.addslashes($flatSql).'");';
        }

        if (! preg_match('/select\s+(.+?)\s+from\s+`?([a-zA-Z_][\w]*)`?/i', $flatSql, $selectAndFrom)) {
            return 'DB::select("'.addslashes($flatSql).'");';
        }

        $selectRaw = trim($selectAndFrom[1] ?? '*');
        $table     = $selectAndFrom[2] ?? 'table_name';
        $builder   = ["DB::table('{$table}')"];

        $joins = [];
        preg_match_all('/\bjoin\s+`?([a-zA-Z_][\w]*)`?\s+on\s+([^\s]+)\s*=\s*([^\s]+)(?:\s|$)/i', $flatSql, $joinMatches, PREG_SET_ORDER);
        foreach ($joinMatches as $join) {
            $joinTable = $join[1] ?? null;
            $left      = isset($join[2]) ? trim($join[2], '` ') : null;
            $right     = isset($join[3]) ? trim($join[3], '` ') : null;

            if ($joinTable && $left && $right) {
                $joins[] = "    ->join('{$joinTable}', '{$left}', '=', '{$right}')";
            }
        }

        $whereChains = [];
        if (preg_match('/\bwhere\b\s+(.+?)(?:\border\s+by\b|\blimit\b|\boffset\b|$)/i', $flatSql, $wherePart)) {
            $whereExpr  = trim($wherePart[1] ?? '');
            $conditions = preg_split('/\s+and\s+/i', $whereExpr) ?: [];

            foreach ($conditions as $condition) {
                if (preg_match('/`?([a-zA-Z_][\w\.]+)`?\s*(=|>=|<=|>|<|!=|<>)\s*(.+)$/i', trim($condition), $c)) {
                    $column      = trim($c[1], '` ');
                    $operator    = $c[2] ?? '=';
                    $value       = trim($c[3] ?? '?', ' ');
                    $whereChains[] = "    ->where('{$column}', '{$operator}', {$this->toPhpLiteral($value)})";
                }
            }
        }

        if (! empty($joins)) {
            $builder = array_merge($builder, $joins);
        }

        if (! empty($whereChains)) {
            $builder = array_merge($builder, $whereChains);
        }

        if (preg_match('/\border\s+by\s+`?([a-zA-Z_][\w\.]*)`?\s*(asc|desc)?/i', $flatSql, $order)) {
            $orderBy   = trim($order[1] ?? '', '` ');
            $direction = strtolower($order[2] ?? 'asc');
            $builder[] = $direction === 'desc'
                ? "    ->orderByDesc('{$orderBy}')"
                : "    ->orderBy('{$orderBy}')";
        }

        if (str_contains(strtolower($flatSql), ' distinct')) {
            $builder[] = '    ->distinct()';
        }

        if ($selectRaw === '*') {
            $builder[] = "    ->select('*')";
        } else {
            $columns    = array_map(static fn (string $c): string => trim(trim($c), '`'), explode(',', $selectRaw));
            $columns    = array_values(array_filter($columns, static fn (string $c): bool => $c !== ''));
            $columnPhp  = implode(', ', array_map(static fn (string $c): string => "'{$c}'", $columns));
            $builder[]  = "    ->select([{$columnPhp}])";
        }

        if (preg_match('/\blimit\s+(\d+)/i', $flatSql, $limit)) {
            $builder[] = '    ->limit('.(int) ($limit[1] ?? 0).')';
        }

        return implode("\n", $builder)."\n    ->get();";
    }

    private function toPhpLiteral(string $value): string
    {
        $trimmed = trim($value);

        if ($trimmed === '?') {
            return '$value';
        }

        if (is_numeric($trimmed)) {
            return $trimmed;
        }

        $trimmed = trim($trimmed, "'\"");

        if (strtolower($trimmed) === 'null') {
            return 'null';
        }

        return "'".addslashes($trimmed)."'";
    }

    private function escapeForPhpString(string $sql): string
    {
        return str_replace(['\\', '"'], ['\\\\', '\\"'], $sql);
    }
}
