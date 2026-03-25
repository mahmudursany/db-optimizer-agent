<?php

namespace Mdj\DbOptimizer\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

class QueryMetricsStore
{
    /** @var array<int, array<string, mixed>> */
    private array $buffer = [];

    public function record(array $metric): void
    {
        $this->buffer[] = $metric;
    }

    public function count(): int
    {
        return count($this->buffer);
    }

    public function all(): Collection
    {
        return collect($this->buffer);
    }

    public function flushSnapshot(array $meta): void
    {
        if ($this->buffer === []) {
            return;
        }

        $disk = (string) config('db_optimizer.storage_disk', 'local');
        $path = trim((string) config('db_optimizer.storage_path', 'db-optimizer'), '/');

        $payload = [
            'captured_at' => now()->toIso8601String(),
            'meta' => $meta,
            'queries' => $this->buffer,
        ];

        $line = json_encode($payload, JSON_UNESCAPED_SLASHES);

        if ($line !== false) {
            Storage::disk($disk)->append($path.'/queries-'.now()->format('Y-m-d').'.ndjson', $line);
        }

        $this->buffer = [];
    }
}
