# Design: wprigollopes/laravel-tombstone

## Purpose

Laravel wrapper for `scheb/tombstone` that provides a ready-to-use dead code detection experience. Install via `composer require`, auto-discovered, and immediately functional. Artisan command for report generation.

## Decisions

- **Package**: `wprigollopes/laravel-tombstone` / `Wprigollopes\LaravelTombstone`
- **Laravel**: 11+ (PHP 8.2+)
- **Handlers**: Configurable — AnalyzerLog (default) or Laravel Log Channel
- **Buffering**: In-memory buffer with auto-flush via terminate middleware
- **Deduplication**: Cache-based, configurable TTL and store
- **Reports**: Console (default) + HTML via `--html` flag
- **Config**: Publishable via `vendor:publish --tag=tombstone-config`

## Package Structure

```
wprigollopes/laravel-tombstone/
├── composer.json
├── config/
│   └── tombstone.php
├── src/
│   ├── TombstoneServiceProvider.php
│   ├── Middleware/
│   │   └── FlushTombstones.php
│   ├── Handler/
│   │   └── DeduplicatingHandler.php
│   ├── Logging/
│   │   └── TombstoneLoggerFactory.php
│   └── Console/
│       └── TombstoneReportCommand.php
```

## Component Design

### TombstoneServiceProvider

**register():**
- Merge default config
- Build `Graveyard` via `GraveyardBuilder`:
  - Set `rootDirectory` from config
  - Set `stackTraceDepth` from config
  - Attach handler based on config (`analyzer_log` or `log_channel`)
  - Wrap handler in `DeduplicatingHandler` if dedup enabled
  - Enable buffering if config says so
  - Auto-register to `GraveyardRegistry`
- Bind graveyard as singleton

**boot():**
- Publish config file (`tombstone-config` tag)
- Register `FlushTombstones` as global terminate middleware
- Register `TombstoneReportCommand` (console only)
- Ensure `storage/tombstone` directory exists when using analyzer_log handler

### config/tombstone.php

```php
return [
    'enabled' => env('TOMBSTONE_ENABLED', true),
    'handler' => env('TOMBSTONE_HANDLER', 'analyzer_log'),
    'buffer' => true,
    'stack_trace_depth' => 5,
    'root_directory' => base_path(),

    'analyzer_log' => [
        'log_dir' => storage_path('tombstone'),
        'size_limit' => null,
        'use_locking' => true,
    ],

    'log_channel' => [
        'channel' => 'tombstone',
        'level' => 'info',
    ],

    'dedup' => [
        'enabled' => true,
        'ttl' => 3600,
        'store' => null,
    ],

    'report' => [
        'source_excludes' => ['vendor', 'node_modules'],
        'html_output' => storage_path('tombstone/report'),
    ],
];
```

### DeduplicatingHandler

- Implements `Scheb\Tombstone\Logger\Handler\HandlerInterface`
- Wraps an inner handler
- Before logging: checks `Cache::store($store)->has('tombstone:' . $hash)`
- If not cached: delegates to inner handler, sets cache key with TTL
- `flush()` delegates to inner handler
- `setFormatter()`/`getFormatter()` delegate to inner handler

### FlushTombstones Middleware

- Implements `terminate()` method (runs after response sent to client)
- Calls `GraveyardRegistry::getGraveyard()->flush()`
- Wrapped in try/catch — logging failures must never break the app
- Registered as global middleware in boot()

### TombstoneLoggerFactory

- Implements `__invoke(array $config): \Monolog\Logger`
- Creates a Monolog Logger instance
- Attaches a Monolog handler that bridges to scheb's `PsrLoggerHandler`
- Used when handler config is `log_channel`
- User adds to their `config/logging.php` channels

### TombstoneReportCommand

- Signature: `tombstone:report {--html : Generate HTML report} {--html-output= : HTML output directory}`
- Uses scheb/tombstone analyzer components:
  1. `ParserTombstoneProvider` — scans source for `tombstone()` calls
  2. `AnalyzerLogProvider` — reads `.tombstone` log files
  3. `Processor` — matches vampires to tombstones
  4. `ConsoleReportGenerator` — always runs, outputs to terminal
  5. `HtmlReportGenerator` — runs when `--html` flag present
- Reads source paths and excludes from config
- Reads log directory from config

## Data Flow

```
tombstone('2024-01-01')
  → GraveyardRegistry::getGraveyard()
  → BufferedGraveyard::logTombstoneCall()  [stores in memory]
  → ... request continues ...
  → Response sent to client
  → FlushTombstones::terminate()
  → BufferedGraveyard::flush()
  → DeduplicatingHandler::log()
    → Cache check (skip if recent duplicate)
    → AnalyzerLogHandler::log()  [writes .tombstone file]
       OR PsrLoggerHandler::log()  [writes to Laravel log channel]
```

## Install Experience

```bash
# Install
composer require wprigollopes/laravel-tombstone

# Ready to use immediately (auto-discovered)
# Place in code:
tombstone('2024-01-01');

# Optional: customize config
php artisan vendor:publish --tag=tombstone-config

# Generate report
php artisan tombstone:report
php artisan tombstone:report --html
php artisan tombstone:report --html --html-output=/path/to/output
```
