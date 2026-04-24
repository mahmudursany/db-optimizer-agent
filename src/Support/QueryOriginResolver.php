<?php

namespace Mdj\DbOptimizer\Support;

class QueryOriginResolver
{
    /**
     * @return array{file: string|null, line: int|null, class: string|null, function: string|null}
     */
    public function resolve(): array
    {
        $frames = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 40);

        foreach ($frames as $frame) {
            $file = $frame['file'] ?? null;

            if (! is_string($file) || $this->shouldSkipFile($file)) {
                continue;
            }

            return [
                'file' => $this->toRelativePath($file),
                'line' => $frame['line'] ?? null,
                'class' => $frame['class'] ?? null,
                'function' => $frame['function'] ?? null,
            ];
        }

        return [
            'file' => null,
            'line' => null,
            'class' => null,
            'function' => null,
        ];
    }

    private function shouldSkipFile(string $file): bool
    {
        $normalized = str_replace('\\\\', '/', $file);

        return str_contains($normalized, '/vendor/')
            || str_contains($normalized, '/packages/db-optimizer-agent/')
            || str_contains($normalized, '/storage/framework/views/')
            || str_contains($normalized, '/storage/framework/cache/')
            || str_contains($normalized, '/bootstrap/app.php')
            || str_contains($normalized, '/public/index.php');
    }

    private function toRelativePath(string $file): string
    {
        $base = str_replace('\\\\', '/', base_path()).'/';
        $normalized = str_replace('\\\\', '/', $file);

        if (str_starts_with($normalized, $base)) {
            return substr($normalized, strlen($base));
        }

        return $normalized;
    }
}
