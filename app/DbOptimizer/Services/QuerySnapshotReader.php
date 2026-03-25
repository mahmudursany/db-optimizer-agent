<?php

namespace App\DbOptimizer\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;

class QuerySnapshotReader
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function latest(int $limit = 50, ?string $sinceIso = null): array
    {
        $disk = (string) config('db_optimizer.storage_disk', 'local');
        $path = trim((string) config('db_optimizer.storage_path', 'db-optimizer'), '/');
        $since = $this->parseSince($sinceIso);

        $files = array_values(array_filter(
            Storage::disk($disk)->files($path),
            static fn (string $file): bool => str_ends_with($file, '.ndjson')
        ));

        rsort($files);

        $snapshots = [];

        foreach ($files as $file) {
            $content = Storage::disk($disk)->get($file);

            foreach (preg_split('/\r\n|\r|\n/', $content) ?: [] as $line) {
                $line = trim($line);

                if ($line === '') {
                    continue;
                }

                $decoded = json_decode($line, true);

                if (! is_array($decoded)) {
                    continue;
                }

                if ($since !== null && ! $this->isAfterSince($decoded, $since)) {
                    continue;
                }

                $decoded['id'] = $this->snapshotId($decoded);
                $decoded['summary'] = $this->buildSummary($decoded);
                $snapshots[] = $decoded;
            }
        }

        usort($snapshots, static function (array $a, array $b): int {
            return strcmp((string) ($b['captured_at'] ?? ''), (string) ($a['captured_at'] ?? ''));
        });

        return array_slice($snapshots, 0, $limit);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(string $id): ?array
    {
        foreach ($this->latest(500, null) as $snapshot) {
            if (($snapshot['id'] ?? null) === $id) {
                return $snapshot;
            }
        }

        return null;
    }

    public function clear(): void
    {
        $disk = (string) config('db_optimizer.storage_disk', 'local');
        $path = trim((string) config('db_optimizer.storage_path', 'db-optimizer'), '/');

        $files = array_values(array_filter(
            Storage::disk($disk)->files($path),
            static fn (string $file): bool => str_ends_with($file, '.ndjson')
        ));

        if ($files !== []) {
            Storage::disk($disk)->delete($files);
        }
    }

    private function parseSince(?string $sinceIso): ?Carbon
    {
        if (! is_string($sinceIso) || trim($sinceIso) === '') {
            return null;
        }

        try {
            return Carbon::parse($sinceIso);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function isAfterSince(array $snapshot, Carbon $since): bool
    {
        $capturedAt = $snapshot['captured_at'] ?? null;

        if (! is_string($capturedAt) || trim($capturedAt) === '') {
            return false;
        }

        try {
            return Carbon::parse($capturedAt)->greaterThanOrEqualTo($since);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function snapshotId(array $snapshot): string
    {
        return sha1(json_encode([
            'captured_at' => $snapshot['captured_at'] ?? null,
            'route' => Arr::get($snapshot, 'meta.route'),
            'method' => Arr::get($snapshot, 'meta.method'),
            'query_count' => Arr::get($snapshot, 'meta.query_count'),
        ]));
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    private function buildSummary(array $snapshot): array
    {
        $queries = is_array($snapshot['queries'] ?? null) ? $snapshot['queries'] : [];

        $totalTime = 0.0;
        $slowCount = 0;
        $nPlusOneCount = 0;
        $missingIndexCount = 0;
        $cacheCandidateCount = 0;

        foreach ($queries as $query) {
            if (! is_array($query)) {
                continue;
            }

            $time = (float) ($query['time_ms'] ?? 0);
            $totalTime += $time;

            if (isset($query['explain'])) {
                $slowCount++;
            }

            if ((bool) Arr::get($query, 'detectors.n_plus_one.is_suspected', false)) {
                $nPlusOneCount++;
            }

            $missingIndexes = Arr::get($query, 'detectors.missing_indexes', []);
            if (is_array($missingIndexes) && $missingIndexes !== []) {
                $missingIndexCount += count($missingIndexes);
            }

            if ((bool) Arr::get($query, 'detectors.cache_candidate.is_candidate', false)) {
                $cacheCandidateCount++;
            }
        }

        $queryCount = count($queries);

        return [
            'query_count' => $queryCount,
            'total_time_ms' => round($totalTime, 2),
            'avg_time_ms' => $queryCount > 0 ? round($totalTime / $queryCount, 2) : 0,
            'slow_count' => $slowCount,
            'n_plus_one_count' => $nPlusOneCount,
            'missing_index_suggestions' => $missingIndexCount,
            'cache_candidate_count' => $cacheCandidateCount,
        ];
    }
}
