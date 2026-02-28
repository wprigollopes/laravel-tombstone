<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Enable Tombstone Logging
    |--------------------------------------------------------------------------
    |
    | When disabled, tombstone() calls become no-ops. Useful for toggling
    | in production without removing code.
    |
    */
    'enabled' => env('TOMBSTONE_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Handler
    |--------------------------------------------------------------------------
    |
    | Which handler processes tombstone calls.
    | Supported: "analyzer_log", "log_channel"
    |
    */
    'handler' => env('TOMBSTONE_HANDLER', 'analyzer_log'),

    /*
    |--------------------------------------------------------------------------
    | Buffering
    |--------------------------------------------------------------------------
    |
    | When enabled, tombstone calls are buffered in memory and flushed
    | after the response is sent (via terminate middleware).
    |
    */
    'buffer' => true,

    /*
    |--------------------------------------------------------------------------
    | Stack Trace Depth
    |--------------------------------------------------------------------------
    |
    | Number of stack frames to capture per tombstone call.
    | Higher values give more context but use more memory/disk.
    |
    */
    'stack_trace_depth' => 5,

    /*
    |--------------------------------------------------------------------------
    | Root Directory
    |--------------------------------------------------------------------------
    |
    | The project root directory. Used for resolving relative file paths
    | in tombstone logs and reports.
    |
    */
    'root_directory' => base_path(),

    /*
    |--------------------------------------------------------------------------
    | Analyzer Log Handler
    |--------------------------------------------------------------------------
    |
    | Writes .tombstone files compatible with scheb/tombstone-analyzer.
    | This is the default handler and required for report generation.
    |
    */
    'analyzer_log' => [
        'log_dir' => storage_path('tombstone'),
        'size_limit' => null,
        'use_locking' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Log Channel Handler
    |--------------------------------------------------------------------------
    |
    | Routes tombstone logs through a Laravel log channel.
    | Add a 'tombstone' channel to your config/logging.php to use this.
    |
    */
    'log_channel' => [
        'channel' => 'tombstone',
        'level' => 'info',
    ],

    /*
    |--------------------------------------------------------------------------
    | Deduplication
    |--------------------------------------------------------------------------
    |
    | Prevents logging the same tombstone repeatedly using Laravel's cache.
    | Reduces log volume significantly for tombstones in hot code paths.
    |
    */
    'dedup' => [
        'enabled' => true,
        'ttl' => 3600,
        'store' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Report Settings
    |--------------------------------------------------------------------------
    |
    | Configuration for the tombstone:report artisan command.
    |
    */
    'report' => [
        'source_excludes' => ['vendor', 'node_modules', 'storage'],
        'html_output' => storage_path('tombstone/report'),
    ],

];
