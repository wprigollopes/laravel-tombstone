# Laravel Tombstone

A Laravel wrapper for [scheb/tombstone](https://github.com/scheb/tombstone) that makes dead code detection effortless. Install, place markers, deploy, and discover what's actually unused.

## Background

I first used `scheb/tombstone` a few years ago on a legacy codebase that had grown organically over a long time. Setting it up back then was not trivial — the project had no framework conventions to lean on, configuration was manual, and wiring the logging and reporting required a fair amount of glue code. But once it was running, it worked beautifully. Tombstones revealed entire modules that hadn't been touched in months, jobs that were never dispatched, and API endpoints that no client had called in over a year. We removed thousands of lines with confidence.

The tombstone methodology proved itself then, and it remains just as relevant today. Modern static analysis tools like PHPStan, Psalm, and Larastan are excellent at catching unreferenced code, type mismatches, and structural issues. But they operate on what the code *could* do, not what it *actually does* at runtime. A controller method that's properly type-hinted and referenced in a route file will pass every static analysis check — even if no request has hit that route in six months.

With Laravel's rise as the dominant PHP framework, most new projects and many migrated ones follow its conventions. That creates an opportunity: a package that integrates tombstone detection natively into Laravel, respecting its service provider lifecycle, configuration system, caching layer, and artisan commands. Instead of the manual setup I went through years ago, the goal here is `composer require` and you're ready to go — even on complex, large-scale applications with thousands of routes, dozens of jobs, and years of accumulated code.

## The Problem

Static analysis tools can tell you when a function isn't referenced anywhere in your codebase. But in real-world Laravel applications, code can be "alive" in the IDE and "dead" in production:

- **API endpoints** that no client calls anymore
- **Queued jobs** that were written for a one-time migration and never dispatched again
- **Artisan commands** created for a specific operational situation that passed months ago
- **Service methods** behind feature flags that were never turned on
- **Event listeners** for events that are no longer fired
- **Middleware** registered but applied to routes nobody hits

These aren't dead code by static analysis standards — they're imported, referenced, and syntactically correct. But they are **unused code**, and they slow down both performance and maintainability. Every unused class is another file to read during onboarding, another thing to update during refactors, another surface for bugs to hide in.

This package exists because I've dealt with this problem across projects that grew over years of updates and feature iterations. At a certain scale, you simply cannot assert which pieces of code are truly exercised in production without runtime evidence.

## How It Works

The concept comes from the tombstone methodology: you place a `tombstone()` function call inside code you suspect is unused. If that code runs in production, the tombstone is "activated" (a "vampire") and gets logged. If it's never activated over a meaningful period, you have strong evidence the code is dead and can be safely removed.

```
Place tombstone -> Deploy -> Wait -> Generate report -> Remove dead code
```

## Installation

```bash
composer require wprigollopes/laravel-tombstone
```

That's it. The package auto-discovers its service provider, registers the `tombstone()` function globally, and is ready to use with sensible defaults.

### Publish the config (optional)

```bash
php artisan vendor:publish --tag=tombstone-config
```

## How to Use

### Step 1: Place tombstones in suspect code

Add `tombstone()` calls to any code you suspect might be unused. Pass a date string so you know when the tombstone was placed:

```php
class LegacyExportController extends Controller
{
    public function export(Request $request)
    {
        tombstone('2025-03-01');

        // ... old export logic
    }
}
```

```php
class SyncInventoryJob implements ShouldQueue
{
    public function handle()
    {
        tombstone('2025-03-01');

        // ... was this job ever dispatched after the migration?
    }
}
```

```php
class GenerateMonthlyReport extends Command
{
    public function handle()
    {
        tombstone('2025-03-01');

        // ... is this command still in any cron schedule?
    }
}
```

You can add descriptive labels for context:

```php
tombstone('2025-03-01', 'old-payment-gateway');
tombstone('2025-03-01', 'v1-api-endpoint');
tombstone('2025-03-01', 'pre-refactor-helper');
```

### Step 2: Deploy and wait

Deploy your code with the tombstones to production (or staging). Let it run for a meaningful period — a week, a sprint, a full billing cycle. The longer you wait, the more confident you can be.

During this period, every time a tombstone is hit, it gets logged to `storage/tombstone/` as a `.tombstone` file.

### Step 3: Generate the report

```bash
# Console output — quick overview
php artisan tombstone:report

# HTML report — detailed, shareable
php artisan tombstone:report --html

# HTML report to a custom directory
php artisan tombstone:report --html --html-output=public/tombstone-report
```

The report shows three categories:

- **Dead** — tombstones that were never activated. This code was not executed during the observation period. Strong candidate for removal.
- **Undead** — tombstones that were activated. This code is still in use. Remove the tombstone and keep the code.
- **Deleted** — log entries for tombstones that no longer exist in the source code (already removed).

### Step 4: Remove dead code

For any code marked as "dead", review it and remove both the `tombstone()` call and the surrounding unused code. Commit, deploy, repeat.

## Performance

The original `scheb/tombstone` library writes logs synchronously to disk on every `tombstone()` call. In a Laravel application handling thousands of requests per second, this can become a bottleneck.

This package addresses performance with two mechanisms:

### Buffered flush

Tombstone calls are buffered in memory during the request lifecycle. The actual disk writes happen **after the response is sent** to the client, via Laravel's terminate middleware. Your users never wait for tombstone I/O.

### Cache-based deduplication

By default, once a tombstone is logged, it won't be logged again for 1 hour (configurable). This uses Laravel's cache, so if you're on Redis, the overhead is a single `has()` check per tombstone call. This dramatically reduces log volume when tombstones sit in hot code paths.

```php
// config/tombstone.php
'dedup' => [
    'enabled' => true,
    'ttl' => 3600,       // seconds between duplicate logs
    'store' => null,     // null = default cache driver
],
```

Set `ttl` to `0` to disable deduplication entirely if you need to log every single call.

## CI/CD Integration

The console output works well for local development. For CI/CD pipelines, use the HTML report:

```bash
php artisan tombstone:report --html --html-output=storage/tombstone/report
```

You can then publish the HTML report as a build artifact, serve it from a static host, or integrate it into your deployment dashboard.

The console output can also be captured in CI logs for a quick summary without generating files.

## Configuration

| Key | Default | Description |
|---|---|---|
| `enabled` | `true` | Toggle tombstone logging on/off via `TOMBSTONE_ENABLED` env var |
| `handler` | `analyzer_log` | `analyzer_log` writes `.tombstone` files; `log_channel` routes through Laravel's logger |
| `buffer` | `true` | Buffer calls in memory, flush after response |
| `stack_trace_depth` | `5` | Stack frames captured per call |
| `dedup.enabled` | `true` | Cache-based deduplication |
| `dedup.ttl` | `3600` | Seconds before a tombstone can be logged again |
| `dedup.store` | `null` | Cache store (null = default) |
| `analyzer_log.log_dir` | `storage/tombstone` | Where `.tombstone` log files are written |
| `report.source_excludes` | `['vendor', 'node_modules', 'storage']` | Directories excluded from source scanning |
| `report.html_output` | `storage/tombstone/report` | Default HTML report output directory |

### Using the Laravel Log Channel handler

If you prefer routing tombstone data through Laravel's logging system instead of the analyzer log files:

```php
// config/tombstone.php
'handler' => 'log_channel',

// config/logging.php
'channels' => [
    'tombstone' => [
        'driver' => 'custom',
        'via' => \Wprigollopes\LaravelTombstone\Logging\TombstoneLoggerFactory::class,
        'path' => storage_path('logs/tombstone.log'),
        'level' => 'info',
    ],
],
```

> Note: the `tombstone:report` command reads from the `analyzer_log` directory. If you use the `log_channel` handler, the artisan report won't have data to analyze. Use `analyzer_log` (the default) if you want report generation.

## Requirements

- PHP 8.2+
- Laravel 11 or 12

## Credits

This package wraps the excellent [scheb/tombstone](https://github.com/scheb/tombstone) library by Christian Scheb. The original library provides the core tombstone mechanics — runtime logging, static analysis, and report generation. This package adds the Laravel integration layer: auto-discovery, configuration publishing, buffered flush, cache deduplication, and artisan commands.

Built with the help of [Claude](https://claude.ai) by Anthropic. The architecture, implementation plan, and code were developed collaboratively using Claude Code — from initial brainstorming and design decisions through TDD implementation and code quality checks with Larastan and Rector.

## License

MIT
