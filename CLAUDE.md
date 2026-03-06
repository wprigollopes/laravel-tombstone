# Laravel Tombstone - AI Implementation Guide

## What This Package Does

Laravel wrapper for [scheb/tombstone](https://github.com/scheb/tombstone) — runtime dead code detection for Laravel applications. Users place `tombstone('2025-03-01')` calls in suspect code, deploy, wait, then run `php artisan tombstone:report` to see what was never executed. Code that's never hit is "dead" and safe to remove.

This is **not** static analysis. It detects code that is syntactically valid and referenced but never actually runs in production (unused API endpoints, old jobs, abandoned commands, etc.).

## Project Structure

```
src/
├── TombstoneServiceProvider.php       # Core: registers graveyard, middleware, config, commands
├── Handler/
│   └── DeduplicatingHandler.php       # Decorator: cache-based dedup to avoid repeated logging
├── Middleware/
│   └── FlushTombstones.php            # Terminate middleware: flushes buffered tombstones after response
├── Console/
│   └── TombstoneReportCommand.php     # `tombstone:report` artisan command (console + HTML output)
└── Logging/
    └── TombstoneLoggerFactory.php     # Monolog factory for Laravel log channel handler

config/
└── tombstone.php                      # Published config with all options

tests/
├── TombstoneServiceProviderTest.php   # Unit: provider registration, config merging
├── DeduplicatingHandlerTest.php       # Unit: cache dedup logic
├── FlushTombstonesTest.php            # Unit: middleware passthrough + terminate flush
├── TombstoneLoggerFactoryTest.php     # Unit: Monolog logger creation
├── TombstoneReportCommandTest.php     # Unit: artisan command
├── IntegrationTest.php                # E2E: tombstone call → flush → .tombstone file created
└── fixtures/source/SampleService.php  # Fixture with tombstone() call for report tests
```

## Architecture & Key Concepts

### Service Provider Lifecycle

1. **register()**: Merges config, creates `GraveyardInterface` singleton via `GraveyardBuilder`
   - Wraps handler in `DeduplicatingHandler` if dedup enabled
   - Enables buffering if configured (default: true)
   - Calls `autoRegister()` to register with `GraveyardRegistry` (global static)
2. **boot()**: Eagerly resolves graveyard, pushes terminate middleware, registers artisan command

### Two Handler Strategies

- **`analyzer_log`** (default): Writes `.tombstone` JSON files to `storage/tombstone/`. Required for `tombstone:report`.
- **`log_channel`**: Routes through Laravel's logging system via `PsrLoggerHandler`. Does NOT support report generation.

### Performance Design

- **Buffering**: Tombstone calls are collected in memory during request. Actual I/O happens in `terminate()` middleware — after the response is sent to the client.
- **Deduplication**: `DeduplicatingHandler` uses Laravel Cache (`Cache::store()`) to skip logging the same tombstone within a TTL window (default 1 hour). A single `has()` check per tombstone call.

### Report Generation (`tombstone:report`)

Uses `scheb/tombstone-analyzer` classes directly:
1. `ParserTombstoneProvider` scans PHP source for `tombstone()` calls
2. `AnalyzerLogProvider` reads `.tombstone` log files
3. `Processor` + `VampireMatcher` cross-references to classify:
   - **Dead**: tombstone in source, never logged → safe to remove
   - **Undead** (vampire): tombstone in source AND logged → code is alive
   - **Deleted**: logged but no longer in source → already removed

## Tech Stack & Requirements

- **PHP 8.2+**, **Laravel 11 or 12**
- **scheb/tombstone ^1.10** (core tombstone library)
- **orchestra/testbench** for testing (provides Laravel app scaffolding)
- **PHPUnit 11**, **Larastan 3**, **Rector 2** for quality

## Namespace

`Wprigollopes\LaravelTombstone\` — PSR-4 mapped to `src/`
`Wprigollopes\LaravelTombstone\Tests\` — PSR-4 mapped to `tests/`

## Key scheb/tombstone Classes Used

| Class | Role |
|---|---|
| `GraveyardBuilder` | Fluent builder for configuring the graveyard |
| `GraveyardInterface` | The graveyard instance that receives tombstone calls |
| `GraveyardRegistry` | Global static holder — `tombstone()` function calls this |
| `AnalyzerLogHandler` | Writes `.tombstone` JSON log files |
| `PsrLoggerHandler` | Delegates to a PSR-3 logger |
| `HandlerInterface` | Contract for log handlers (implemented by `DeduplicatingHandler`) |
| `Vampire` | Model representing an activated tombstone (has hash for dedup) |

## Config Reference (config/tombstone.php)

| Key | Default | Notes |
|---|---|---|
| `enabled` | `env('TOMBSTONE_ENABLED', true)` | Master toggle |
| `handler` | `'analyzer_log'` | `analyzer_log` or `log_channel` |
| `buffer` | `true` | Buffer in memory, flush on terminate |
| `stack_trace_depth` | `5` | Frames captured per call |
| `root_directory` | `base_path()` | For relative path resolution |
| `analyzer_log.log_dir` | `storage_path('tombstone')` | Where .tombstone files go |
| `analyzer_log.size_limit` | `null` | Max log file size |
| `analyzer_log.use_locking` | `true` | File locking for writes |
| `log_channel.channel` | `'tombstone'` | Laravel log channel name |
| `log_channel.level` | `'info'` | Log level |
| `dedup.enabled` | `true` | Cache-based deduplication |
| `dedup.ttl` | `3600` | Seconds between duplicate logs |
| `dedup.store` | `null` | Cache store (null = default driver) |
| `report.source_excludes` | `['vendor', 'node_modules', 'storage']` | Dirs to skip in source scan |
| `report.html_output` | `storage_path('tombstone/report')` | HTML report directory |

## Running Tests

```bash
./vendor/bin/phpunit
```

All tests use Orchestra Testbench. The `IntegrationTest` creates a real graveyard, calls `tombstone()`, flushes, and verifies `.tombstone` files are written.

## Static Analysis

```bash
./vendor/bin/phpstan analyse    # Larastan
./vendor/bin/rector process     # Rector
```

## Development Guidelines

- All PHP files use `declare(strict_types=1)`
- Tombstone failures must **never** break the application — wrap in try/catch where needed (see `FlushTombstones::terminate()`)
- The `DeduplicatingHandler` is a decorator pattern — it wraps any `HandlerInterface`
- Config values always have fallback defaults (never assume config exists)
- The package uses Laravel's auto-discovery (`extra.laravel.providers` in composer.json)
- When adding new handlers, implement `Scheb\Tombstone\Logger\Handler\HandlerInterface`
- The `tombstone()` function is globally available via autoloaded file from scheb/tombstone

## Common Tasks

### Adding a new handler type
1. Create handler class implementing `HandlerInterface` in `src/Handler/`
2. Add a case to `TombstoneServiceProvider::buildHandler()` match expression
3. Add config section in `config/tombstone.php`
4. Add tests

### Adding a new report format
1. Create a report generator implementing scheb's report generator interface
2. Add it to the `$reportGenerators` array in `TombstoneReportCommand::handle()`
3. Add a CLI option to the command signature

### Modifying dedup behavior
The `DeduplicatingHandler` is self-contained. Cache key format: `tombstone:{hash}` where hash comes from `Vampire::getTombstone()->getHash()`.
