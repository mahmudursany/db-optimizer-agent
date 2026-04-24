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
        $sql = (string) ($metric['raw_sql'] ?? $event->sql);
        $normalizedSql = $this->normalizeSql($event->sql);

        if (str_starts_with($normalizedSql, 'select') && str_contains($normalizedSql, 'select *')) {
            $optimized = preg_replace('/\bselect\s+\*/i', 'SELECT id /* add required columns */', $sql) ?? $sql;
            $recommendations[] = $this->make(
                'Select only needed columns',
                'Avoid SELECT * to reduce IO and memory.',
                $sql,
                $optimized,
                92,
                true,
            );
        }

        if ((bool) preg_match('/\bexists\s*\(\s*select\s+\*/i', $normalizedSql)) {
            $optimized = preg_replace('/\bexists\s*\(\s*select\s+\*/i', 'EXISTS (SELECT 1', $sql) ?? $sql;
            $recommendations[] = $this->make(
                'Use SELECT 1 inside EXISTS',
                'EXISTS checks row presence only; selecting columns is unnecessary.',
                $sql,
                $optimized,
                86,
                true,
            );
        }

        if (str_starts_with($normalizedSql, 'select count(')) {
            $optimized = $this->rewriteCountToExists($sql);
            $recommendations[] = $this->make(
                'Use EXISTS instead of COUNT when checking presence',
                'For boolean checks, EXISTS can stop at first match and is usually cheaper.',
                $sql,
                $optimized,
                88,
                true,
            );
        }

        if ((bool) Arr::get($metric, 'detectors.n_plus_one.is_suspected', false)) {
            $recommendations[] = $this->make(
                'Resolve N+1 via eager loading',
                'Repeated single-row relation queries detected.',
                '-- repeated relation query pattern --',
                "Model::query()->with(['relationName'])->get();",
                98,
                false,
            );
        }

        if (! empty(Arr::get($metric, 'detectors.missing_indexes', []))) {
            $first = Arr::first((array) Arr::get($metric, 'detectors.missing_indexes', []));
            $table = is_array($first) ? (string) ($first['table'] ?? 'table_name') : 'table_name';
            $column = is_array($first) ? (string) ($first['column'] ?? 'column_name') : 'column_name';

            $recommendations[] = $this->make(
                'Add index for filter/join column',
                'Missing leading index detected for a WHERE/JOIN column.',
                $sql,
                "ALTER TABLE `{$table}` ADD INDEX `idx_{$table}_{$column}` (`{$column}`);",
                95,
                false,
            );
        }

        if ((bool) Arr::get($metric, 'detectors.cache_candidate.is_candidate', false)) {
            $recommendations[] = $this->make(
                'Cache repeated read query',
                'Static repeated SELECT detected with identical bindings.',
                $sql,
                "Cache::remember('db-opt-key', 300, fn () => DB::select(\"{$this->escapeForPhpString($event->sql)}\"));",
                84,
                true,
            );
        }

        if (str_contains($normalizedSql, ' offset ')) {
            $recommendations[] = $this->make(
                'Prefer keyset pagination over OFFSET',
                'Large OFFSET values degrade performance on big datasets.',
                $sql,
                'SELECT ... WHERE id < :last_seen_id ORDER BY id DESC LIMIT 12',
                76,
                false,
            );
        }

        if (isset($metric['explain'])) {
            $recommendations[] = $this->make(
                'Tune query based on EXPLAIN',
                'Slow query plan indicates scan/sort bottlenecks.',
                $sql,
                '-- add/selective indexes on WHERE + JOIN columns; avoid filesort/temporary --',
                93,
                false,
            );
        }

        foreach ($recommendations as &$recommendation) {
            if ((bool) config('db_optimizer.auto_apply_safe_optimizations', false) && ($recommendation['safe_auto_apply'] ?? false)) {
                $recommendation['auto_apply_eligible'] = true;
+                $recommendation['auto_applied'] = false;
            }
        }
        unset($recommendation);

        usort($recommendations, static fn (array $a, array $b): int => ((int) ($b['priority'] ?? 0)) <=> ((int) ($a['priority'] ?? 0)));

        return array_slice($recommendations, 0, max(1, (int) config('db_optimizer.recommendation_limit', 8)));
    }

    private function normalizeSql(string $sql): string
    {
        return preg_replace('/\s+/', ' ', strtolower(trim($sql))) ?? strtolower(trim($sql));
    }

    private function rewriteCountToExists(string $sql): string
    {
        $normalized = strtolower($sql);
        $fromPos = strpos($normalized, ' from ');

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
    ): array {
        return [
            'title' => $title,
            'description' => $description,
            'current_sql' => $currentSql,
            'optimized_sql' => $optimizedSql,
            'priority' => $priority,
            'safe_auto_apply' => $safeAutoApply,
            'auto_apply_eligible' => false,
        ];
    }

    private function escapeForPhpString(string $sql): string
    {
        return str_replace(['\\', '"'], ['\\\\', '\\"'], $sql);
    }
}
