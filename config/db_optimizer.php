<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Enable Optimizer
    |--------------------------------------------------------------------------
    |
    | Keep this enabled for local development only. You can still force-enable
    | it in other environments by setting DB_OPTIMIZER_ENABLED=true.
    |
    */
    'enabled' => env('DB_OPTIMIZER_ENABLED', env('APP_ENV') === 'local'),

    /*
    |--------------------------------------------------------------------------
    | Capture Settings
    |--------------------------------------------------------------------------
    */
    'capture_console' => env('DB_OPTIMIZER_CAPTURE_CONSOLE', false),
    'sample_rate' => (float) env('DB_OPTIMIZER_SAMPLE_RATE', 1.0),

    /*
    |--------------------------------------------------------------------------
    | Detection Thresholds
    |--------------------------------------------------------------------------
    */
    'slow_query_threshold_ms' => (float) env('DB_OPTIMIZER_SLOW_MS', 50),
    'n_plus_one_repeat_threshold' => (int) env('DB_OPTIMIZER_N1_THRESHOLD', 5),
    'cache_candidate_repeat_threshold' => (int) env('DB_OPTIMIZER_CACHE_REPEAT_THRESHOLD', 8),

    /*
    |--------------------------------------------------------------------------
    | Persistence
    |--------------------------------------------------------------------------
    |
    | Request-level snapshots are appended as NDJSON files for cheap writes
    | and easy dashboard ingestion.
    |
    */
    'storage_disk' => env('DB_OPTIMIZER_STORAGE_DISK', 'local'),
    'storage_path' => env('DB_OPTIMIZER_STORAGE_PATH', 'db-optimizer'),

    /*
    |--------------------------------------------------------------------------
    | Agent & Scanner
    |--------------------------------------------------------------------------
    */
    'agent_token' => env('DB_OPTIMIZER_AGENT_TOKEN', ''),
    'route_prefix' => env('DB_OPTIMIZER_ROUTE_PREFIX', '_db-optimizer'),
    'scanner' => [
        'timeout_seconds' => (int) env('DB_OPTIMIZER_SCANNER_TIMEOUT', 20),
    ],
];
