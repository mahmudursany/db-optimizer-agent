<?php

namespace App\DbOptimizer\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class RemoteProjectScanner
{
    /**
     * @param  array<int, string>  $paths
     * @return array<string, mixed>
     */
    public function scan(string $targetUrl, string $token, array $paths): array
    {
        $baseUrl = rtrim(trim($targetUrl), '/');
        $startedAt = now()->toIso8601String();

        $client = $this->buildClient($token);
        $this->pingAgent($client, $baseUrl);

        $requests = [];

        foreach ($this->normalizePaths($paths) as $path) {
            $url = $this->resolveUrl($baseUrl, $path);
            $start = microtime(true);

            try {
                $response = $client->get($url);

                $requests[] = [
                    'path' => $path,
                    'url' => $url,
                    'status' => $response->status(),
                    'time_ms' => round((microtime(true) - $start) * 1000, 2),
                    'ok' => $response->successful(),
                ];
            } catch (\Throwable $e) {
                $requests[] = [
                    'path' => $path,
                    'url' => $url,
                    'status' => null,
                    'time_ms' => round((microtime(true) - $start) * 1000, 2),
                    'ok' => false,
                    'error' => $e->getMessage(),
                ];
            }
        }

        $snapshots = $this->fetchSnapshots($client, $baseUrl, $startedAt);
        $report = $this->buildReport($baseUrl, $startedAt, $requests, $snapshots);

        return $report;
    }

    private function buildClient(string $token): PendingRequest
    {
        $request = Http::acceptJson()
            ->timeout((int) config('db_optimizer.scanner.timeout_seconds', 20));

        if ($token !== '') {
            $request = $request->withToken($token);
        }

        return $request;
    }

    private function pingAgent(PendingRequest $client, string $baseUrl): void
    {
        $prefix = trim((string) config('db_optimizer.route_prefix', '_db-optimizer'), '/');
        $response = $client->get($baseUrl.'/'.$prefix.'/agent/ping');

        if (! $response->successful()) {
            throw new RuntimeException('Cannot connect to remote agent. Check URL/token and ensure agent routes are enabled.');
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchSnapshots(PendingRequest $client, string $baseUrl, string $since): array
    {
        $prefix = trim((string) config('db_optimizer.route_prefix', '_db-optimizer'), '/');

        $response = $client->get($baseUrl.'/'.$prefix.'/agent/snapshots', [
            'since' => $since,
            'limit' => 500,
        ]);

        if (! $response->successful()) {
            throw new RuntimeException('Snapshot fetch failed from remote agent.');
        }

        $data = $response->json('data');

        return is_array($data) ? $data : [];
    }

    /**
     * @param  array<int, string>  $paths
     * @return array<int, string>
     */
    private function normalizePaths(array $paths): array
    {
        $clean = [];

        foreach ($paths as $path) {
            $path = trim($path);

            if ($path === '') {
                continue;
            }

            $clean[] = $path;
        }

        return $clean === [] ? ['/'] : array_values(array_unique($clean));
    }

    private function resolveUrl(string $baseUrl, string $path): string
    {
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        return $baseUrl.'/'.ltrim($path, '/');
    }

    /**
     * @param  array<int, array<string, mixed>>  $requests
     * @param  array<int, array<string, mixed>>  $snapshots
     * @return array<string, mixed>
     */
    private function buildReport(string $target, string $startedAt, array $requests, array $snapshots): array
    {
        $allQueries = [];

        foreach ($snapshots as $snapshot) {
            $queries = Arr::get($snapshot, 'queries', []);

            if (! is_array($queries)) {
                continue;
            }

            foreach ($queries as $query) {
                if (! is_array($query)) {
                    continue;
                }

                $query['_snapshot_meta'] = Arr::get($snapshot, 'meta', []);
                $query['_captured_at'] = Arr::get($snapshot, 'captured_at');
                $allQueries[] = $query;
            }
        }

        usort($allQueries, static fn (array $a, array $b): int => ((float) ($b['time_ms'] ?? 0)) <=> ((float) ($a['time_ms'] ?? 0)));

        $slowQueries = array_slice(array_values(array_filter($allQueries, static fn (array $q): bool => isset($q['explain']))), 0, 20);
        $nPlusOne = array_slice(array_values(array_filter($allQueries, static fn (array $q): bool => (bool) Arr::get($q, 'detectors.n_plus_one.is_suspected', false))), 0, 20);
        $cacheCandidates = array_slice(array_values(array_filter($allQueries, static fn (array $q): bool => (bool) Arr::get($q, 'detectors.cache_candidate.is_candidate', false))), 0, 20);
        $recommendations = [];
        $autoApplyEligible = [];

        $missingIndexMap = [];

        foreach ($allQueries as $query) {
            $hints = Arr::get($query, 'detectors.missing_indexes', []);

            if (! is_array($hints)) {
                continue;
            }

            foreach ($hints as $hint) {
                if (! is_array($hint)) {
                    continue;
                }

                $table = (string) ($hint['table'] ?? 'unknown');
                $column = (string) ($hint['column'] ?? 'unknown');
                $key = strtolower($table.'.'.$column);

                if (! isset($missingIndexMap[$key])) {
                    $missingIndexMap[$key] = [
                        'table' => $table,
                        'column' => $column,
                        'reason' => (string) ($hint['reason'] ?? ''),
                        'count' => 0,
                    ];
                }

                $missingIndexMap[$key]['count']++;
            }

            $queryRecommendations = Arr::get($query, 'recommendations', []);
            if (is_array($queryRecommendations)) {
                foreach ($queryRecommendations as $recommendation) {
                    if (! is_array($recommendation)) {
                        continue;
                    }

                    $recommendations[] = $recommendation;

                    if ((bool) Arr::get($recommendation, 'auto_apply_eligible', false)) {
                        $autoApplyEligible[] = $recommendation;
                    }
                }
            }
        }

        usort($recommendations, static fn (array $a, array $b): int => ((int) ($b['priority'] ?? 0)) <=> ((int) ($a['priority'] ?? 0)));
        usort($autoApplyEligible, static fn (array $a, array $b): int => ((int) ($b['priority'] ?? 0)) <=> ((int) ($a['priority'] ?? 0)));

        usort($requests, static fn (array $a, array $b): int => ((float) ($b['time_ms'] ?? 0)) <=> ((float) ($a['time_ms'] ?? 0)));

        return [
            'target' => $target,
            'started_at' => $startedAt,
            'finished_at' => now()->toIso8601String(),
            'requests' => $requests,
            'summary' => [
                'request_count' => count($requests),
                'snapshot_count' => count($snapshots),
                'query_count' => count($allQueries),
                'slow_query_count' => count($slowQueries),
                'n_plus_one_count' => count($nPlusOne),
                'missing_index_count' => count($missingIndexMap),
                'cache_candidate_count' => count($cacheCandidates),
                'recommendation_count' => count($recommendations),
                'auto_apply_eligible_count' => count($autoApplyEligible),
            ],
            'issues' => [
                'slow_queries' => $slowQueries,
                'n_plus_one' => $nPlusOne,
                'missing_indexes' => array_values($missingIndexMap),
                'cache_candidates' => $cacheCandidates,
                'recommendations' => array_slice($recommendations, 0, 25),
                'auto_apply_eligible' => array_slice($autoApplyEligible, 0, 25),
            ],
        ];
    }
}
