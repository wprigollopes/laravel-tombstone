# Laravel Tombstone Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Build `wprigollopes/laravel-tombstone`, a Laravel wrapper for `scheb/tombstone` that provides zero-config dead code detection with `composer require` and an artisan report command.

**Architecture:** Service provider bootstraps scheb/tombstone's `Graveyard` with configurable handlers (AnalyzerLog or Laravel Log Channel), cache-based deduplication, and buffered flush via terminate middleware. An artisan command wires scheb's analyzer pipeline for report generation.

**Tech Stack:** PHP 8.2+, Laravel 11+, scheb/tombstone ^1.10, PSR-3, Monolog

---

### Task 1: Scaffold package structure and composer.json

**Files:**
- Create: `composer.json` (overwrite existing minimal one)
- Create: `src/.gitkeep` (ensure directory exists)

**Step 1: Create the package composer.json**

```json
{
    "name": "wprigollopes/laravel-tombstone",
    "description": "Laravel wrapper for scheb/tombstone - dead code detection made easy",
    "type": "library",
    "license": "MIT",
    "require": {
        "php": "^8.2",
        "scheb/tombstone": "^1.10",
        "illuminate/support": "^11.0|^12.0",
        "illuminate/cache": "^11.0|^12.0",
        "illuminate/console": "^11.0|^12.0",
        "illuminate/config": "^11.0|^12.0"
    },
    "require-dev": {
        "orchestra/testbench": "^9.0|^10.0",
        "phpunit/phpunit": "^11.0"
    },
    "autoload": {
        "psr-4": {
            "Wprigollopes\\LaravelTombstone\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Wprigollopes\\LaravelTombstone\\Tests\\": "tests/"
        }
    },
    "extra": {
        "laravel": {
            "providers": [
                "Wprigollopes\\LaravelTombstone\\TombstoneServiceProvider"
            ]
        }
    },
    "minimum-stability": "stable",
    "prefer-stable": true
}
```

**Step 2: Create directory structure**

```bash
mkdir -p src/Handler src/Middleware src/Logging src/Console config tests
```

**Step 3: Commit**

```bash
git init
git add composer.json src/ config/ tests/
git commit -m "feat: scaffold laravel-tombstone package structure"
```

---

### Task 2: Create the publishable config file

**Files:**
- Create: `config/tombstone.php`

**Step 1: Write the config file**

```php
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
```

**Step 2: Commit**

```bash
git add config/tombstone.php
git commit -m "feat: add publishable tombstone config"
```

---

### Task 3: Create DeduplicatingHandler

**Files:**
- Create: `src/Handler/DeduplicatingHandler.php`
- Create: `tests/DeduplicatingHandlerTest.php`

**Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Wprigollopes\LaravelTombstone\Tests;

use Illuminate\Support\Facades\Cache;
use Orchestra\Testbench\TestCase;
use Scheb\Tombstone\Core\Model\FilePathInterface;
use Scheb\Tombstone\Core\Model\StackTrace;
use Scheb\Tombstone\Core\Model\Tombstone;
use Scheb\Tombstone\Core\Model\Vampire;
use Scheb\Tombstone\Logger\Formatter\FormatterInterface;
use Scheb\Tombstone\Logger\Handler\HandlerInterface;
use Wprigollopes\LaravelTombstone\Handler\DeduplicatingHandler;

class DeduplicatingHandlerTest extends TestCase
{
    private HandlerInterface $innerHandler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->innerHandler = $this->createMock(HandlerInterface::class);
    }

    public function testLogDelegatesToInnerHandlerOnFirstCall(): void
    {
        $handler = new DeduplicatingHandler($this->innerHandler, 3600);
        $vampire = $this->createVampire();

        $this->innerHandler->expects($this->once())->method('log')->with($vampire);

        $handler->log($vampire);
    }

    public function testLogSkipsDuplicateWithinTtl(): void
    {
        $handler = new DeduplicatingHandler($this->innerHandler, 3600);
        $vampire = $this->createVampire();

        $this->innerHandler->expects($this->once())->method('log');

        $handler->log($vampire);
        $handler->log($vampire);
    }

    public function testFlushDelegatesToInnerHandler(): void
    {
        $handler = new DeduplicatingHandler($this->innerHandler, 3600);

        $this->innerHandler->expects($this->once())->method('flush');

        $handler->flush();
    }

    public function testFormatterDelegatesToInnerHandler(): void
    {
        $formatter = $this->createMock(FormatterInterface::class);
        $handler = new DeduplicatingHandler($this->innerHandler, 3600);

        $this->innerHandler->expects($this->once())->method('setFormatter')->with($formatter);
        $this->innerHandler->expects($this->once())->method('getFormatter')->willReturn($formatter);

        $handler->setFormatter($formatter);
        $this->assertSame($formatter, $handler->getFormatter());
    }

    public function testDedupDisabledAlwaysLogs(): void
    {
        $handler = new DeduplicatingHandler($this->innerHandler, 0);
        $vampire = $this->createVampire();

        $this->innerHandler->expects($this->exactly(2))->method('log');

        $handler->log($vampire);
        $handler->log($vampire);
    }

    private function createVampire(): Vampire
    {
        $filePath = $this->createMock(FilePathInterface::class);
        $filePath->method('getReferencePath')->willReturn('app/Services/OldService.php');

        $tombstone = new Tombstone('tombstone', ['2024-01-01'], $filePath, 42, 'doSomething');

        return new Vampire(
            date('c'),
            'App\\Http\\Controllers\\TestController::index',
            new StackTrace(),
            $tombstone,
        );
    }
}
```

**Step 2: Run test to verify it fails**

```bash
./vendor/bin/phpunit tests/DeduplicatingHandlerTest.php
```

Expected: FAIL — class `DeduplicatingHandler` does not exist.

**Step 3: Write the implementation**

```php
<?php

declare(strict_types=1);

namespace Wprigollopes\LaravelTombstone\Handler;

use Illuminate\Support\Facades\Cache;
use Scheb\Tombstone\Core\Model\Vampire;
use Scheb\Tombstone\Logger\Formatter\FormatterInterface;
use Scheb\Tombstone\Logger\Handler\HandlerInterface;

class DeduplicatingHandler implements HandlerInterface
{
    public function __construct(
        private HandlerInterface $innerHandler,
        private int $ttl = 3600,
        private ?string $store = null,
    ) {
    }

    public function log(Vampire $vampire): void
    {
        if ($this->ttl <= 0) {
            $this->innerHandler->log($vampire);

            return;
        }

        $key = 'tombstone:' . $vampire->getTombstone()->getHash();
        $cache = Cache::store($this->store);

        if ($cache->has($key)) {
            return;
        }

        $cache->put($key, true, $this->ttl);
        $this->innerHandler->log($vampire);
    }

    public function flush(): void
    {
        $this->innerHandler->flush();
    }

    public function setFormatter(FormatterInterface $formatter): void
    {
        $this->innerHandler->setFormatter($formatter);
    }

    public function getFormatter(): FormatterInterface
    {
        return $this->innerHandler->getFormatter();
    }
}
```

**Step 4: Run test to verify it passes**

```bash
./vendor/bin/phpunit tests/DeduplicatingHandlerTest.php
```

Expected: All 5 tests PASS.

**Step 5: Commit**

```bash
git add src/Handler/DeduplicatingHandler.php tests/DeduplicatingHandlerTest.php
git commit -m "feat: add DeduplicatingHandler with cache-based dedup"
```

---

### Task 4: Create FlushTombstones middleware

**Files:**
- Create: `src/Middleware/FlushTombstones.php`
- Create: `tests/FlushTombstonesTest.php`

**Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Wprigollopes\LaravelTombstone\Tests;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Orchestra\Testbench\TestCase;
use Scheb\Tombstone\Logger\Graveyard\GraveyardInterface;
use Scheb\Tombstone\Logger\Graveyard\GraveyardRegistry;
use Wprigollopes\LaravelTombstone\Middleware\FlushTombstones;

class FlushTombstonesTest extends TestCase
{
    public function testTerminateFlushesGraveyard(): void
    {
        $graveyard = $this->createMock(GraveyardInterface::class);
        $graveyard->expects($this->once())->method('flush');

        GraveyardRegistry::setGraveyard($graveyard);

        $middleware = new FlushTombstones();
        $middleware->terminate(
            Request::create('/test'),
            new Response(),
        );
    }

    public function testHandlePassesRequestThrough(): void
    {
        $middleware = new FlushTombstones();
        $request = Request::create('/test');

        $response = $middleware->handle($request, function ($req) {
            return new Response('ok');
        });

        $this->assertEquals('ok', $response->getContent());
    }

    public function testTerminateDoesNotThrowWhenNoGraveyard(): void
    {
        // Reset registry to trigger exception internally
        $reflection = new \ReflectionClass(GraveyardRegistry::class);
        $property = $reflection->getProperty('graveyard');
        $property->setAccessible(true);
        $property->setValue(null, null);

        $middleware = new FlushTombstones();

        // Should not throw
        $middleware->terminate(
            Request::create('/test'),
            new Response(),
        );

        $this->assertTrue(true);
    }
}
```

**Step 2: Run test to verify it fails**

```bash
./vendor/bin/phpunit tests/FlushTombstonesTest.php
```

Expected: FAIL — class `FlushTombstones` does not exist.

**Step 3: Write the implementation**

```php
<?php

declare(strict_types=1);

namespace Wprigollopes\LaravelTombstone\Middleware;

use Closure;
use Illuminate\Http\Request;
use Scheb\Tombstone\Logger\Graveyard\GraveyardRegistry;
use Symfony\Component\HttpFoundation\Response;

class FlushTombstones
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        try {
            GraveyardRegistry::getGraveyard()->flush();
        } catch (\Throwable) {
            // Tombstone flush failures must never break the application
        }
    }
}
```

**Step 4: Run test to verify it passes**

```bash
./vendor/bin/phpunit tests/FlushTombstonesTest.php
```

Expected: All 3 tests PASS.

**Step 5: Commit**

```bash
git add src/Middleware/FlushTombstones.php tests/FlushTombstonesTest.php
git commit -m "feat: add FlushTombstones terminate middleware"
```

---

### Task 5: Create TombstoneLoggerFactory

**Files:**
- Create: `src/Logging/TombstoneLoggerFactory.php`
- Create: `tests/TombstoneLoggerFactoryTest.php`

**Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Wprigollopes\LaravelTombstone\Tests;

use Monolog\Logger;
use Orchestra\Testbench\TestCase;
use Wprigollopes\LaravelTombstone\Logging\TombstoneLoggerFactory;

class TombstoneLoggerFactoryTest extends TestCase
{
    public function testInvokeReturnsMonologLogger(): void
    {
        $factory = new TombstoneLoggerFactory();

        $logger = $factory([
            'driver' => 'custom',
            'via' => TombstoneLoggerFactory::class,
            'path' => storage_path('logs/tombstone.log'),
            'level' => 'info',
        ]);

        $this->assertInstanceOf(Logger::class, $logger);
        $this->assertSame('tombstone', $logger->getName());
        $this->assertNotEmpty($logger->getHandlers());
    }
}
```

**Step 2: Run test to verify it fails**

```bash
./vendor/bin/phpunit tests/TombstoneLoggerFactoryTest.php
```

Expected: FAIL — class `TombstoneLoggerFactory` does not exist.

**Step 3: Write the implementation**

```php
<?php

declare(strict_types=1);

namespace Wprigollopes\LaravelTombstone\Logging;

use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;

class TombstoneLoggerFactory
{
    public function __invoke(array $config): Logger
    {
        $logger = new Logger('tombstone');

        $path = $config['path'] ?? storage_path('logs/tombstone.log');
        $level = $config['level'] ?? 'info';

        $logger->pushHandler(new StreamHandler(
            $path,
            Level::fromName($level),
        ));

        return $logger;
    }
}
```

**Step 4: Run test to verify it passes**

```bash
./vendor/bin/phpunit tests/TombstoneLoggerFactoryTest.php
```

Expected: PASS.

**Step 5: Commit**

```bash
git add src/Logging/TombstoneLoggerFactory.php tests/TombstoneLoggerFactoryTest.php
git commit -m "feat: add TombstoneLoggerFactory for Laravel log channel"
```

---

### Task 6: Create TombstoneServiceProvider

**Files:**
- Create: `src/TombstoneServiceProvider.php`
- Create: `tests/TombstoneServiceProviderTest.php`

**Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Wprigollopes\LaravelTombstone\Tests;

use Orchestra\Testbench\TestCase;
use Scheb\Tombstone\Logger\Graveyard\GraveyardInterface;
use Scheb\Tombstone\Logger\Graveyard\GraveyardRegistry;
use Wprigollopes\LaravelTombstone\TombstoneServiceProvider;

class TombstoneServiceProviderTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [TombstoneServiceProvider::class];
    }

    public function testGraveyardIsRegisteredInContainer(): void
    {
        $this->assertInstanceOf(
            GraveyardInterface::class,
            $this->app->make(GraveyardInterface::class),
        );
    }

    public function testGraveyardIsRegisteredGlobally(): void
    {
        $graveyard = GraveyardRegistry::getGraveyard();
        $this->assertInstanceOf(GraveyardInterface::class, $graveyard);
    }

    public function testConfigIsPublishable(): void
    {
        $this->artisan('vendor:publish', [
            '--tag' => 'tombstone-config',
            '--no-interaction' => true,
        ])->assertExitCode(0);
    }

    public function testGraveyardNotRegisteredWhenDisabled(): void
    {
        // Reset from previous tests
        $reflection = new \ReflectionClass(GraveyardRegistry::class);
        $property = $reflection->getProperty('graveyard');
        $property->setAccessible(true);
        $property->setValue(null, null);

        // Recreate app with disabled config
        $this->app['config']->set('tombstone.enabled', false);

        // Re-register provider
        $provider = new TombstoneServiceProvider($this->app);
        $provider->register();

        $this->expectException(\Scheb\Tombstone\Logger\Graveyard\GraveyardNotSetException::class);
        GraveyardRegistry::getGraveyard();
    }

    public function testArtisanCommandIsRegistered(): void
    {
        $this->artisan('tombstone:report', ['--help' => true])
            ->assertExitCode(0);
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('tombstone.enabled', true);
        $app['config']->set('tombstone.handler', 'analyzer_log');
        $app['config']->set('tombstone.analyzer_log.log_dir', storage_path('tombstone'));
    }
}
```

**Step 2: Run test to verify it fails**

```bash
./vendor/bin/phpunit tests/TombstoneServiceProviderTest.php
```

Expected: FAIL — class `TombstoneServiceProvider` does not exist.

**Step 3: Write the implementation**

```php
<?php

declare(strict_types=1);

namespace Wprigollopes\LaravelTombstone;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\ServiceProvider;
use Scheb\Tombstone\Logger\Graveyard\GraveyardBuilder;
use Scheb\Tombstone\Logger\Graveyard\GraveyardInterface;
use Scheb\Tombstone\Logger\Handler\AnalyzerLogHandler;
use Scheb\Tombstone\Logger\Handler\HandlerInterface;
use Scheb\Tombstone\Logger\Handler\PsrLoggerHandler;
use Wprigollopes\LaravelTombstone\Console\TombstoneReportCommand;
use Wprigollopes\LaravelTombstone\Handler\DeduplicatingHandler;
use Wprigollopes\LaravelTombstone\Middleware\FlushTombstones;

class TombstoneServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/tombstone.php', 'tombstone');

        if (! $this->app['config']->get('tombstone.enabled', true)) {
            return;
        }

        $this->app->singleton(GraveyardInterface::class, function ($app) {
            $config = $app['config']->get('tombstone');

            $handler = $this->buildHandler($config);

            if ($config['dedup']['enabled'] ?? true) {
                $handler = new DeduplicatingHandler(
                    $handler,
                    (int) ($config['dedup']['ttl'] ?? 3600),
                    $config['dedup']['store'] ?? null,
                );
            }

            $builder = (new GraveyardBuilder())
                ->rootDirectory($config['root_directory'] ?? base_path())
                ->stackTraceDepth((int) ($config['stack_trace_depth'] ?? 5))
                ->withHandler($handler)
                ->autoRegister();

            if ($config['buffer'] ?? true) {
                $builder->buffered();
            }

            return $builder->build();
        });

        // Eagerly resolve to register with GraveyardRegistry
        $this->app->afterResolving(GraveyardInterface::class, function () {
            // GraveyardBuilder::autoRegister() handles registration
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/tombstone.php' => config_path('tombstone.php'),
        ], 'tombstone-config');

        if ($this->app['config']->get('tombstone.enabled', true)) {
            // Eagerly resolve the graveyard so it registers with GraveyardRegistry
            $this->app->make(GraveyardInterface::class);

            // Ensure log directory exists for analyzer_log handler
            if ($this->app['config']->get('tombstone.handler') === 'analyzer_log') {
                $logDir = $this->app['config']->get('tombstone.analyzer_log.log_dir');
                if ($logDir && ! is_dir($logDir)) {
                    @mkdir($logDir, 0755, true);
                }
            }

            // Register terminate middleware
            if ($this->app->bound(Kernel::class)) {
                $kernel = $this->app->make(Kernel::class);
                if (method_exists($kernel, 'pushMiddleware')) {
                    $kernel->pushMiddleware(FlushTombstones::class);
                }
            }
        }

        if ($this->app->runningInConsole()) {
            $this->commands([
                TombstoneReportCommand::class,
            ]);
        }
    }

    private function buildHandler(array $config): HandlerInterface
    {
        return match ($config['handler'] ?? 'analyzer_log') {
            'log_channel' => $this->buildLogChannelHandler($config),
            default => $this->buildAnalyzerLogHandler($config),
        };
    }

    private function buildAnalyzerLogHandler(array $config): AnalyzerLogHandler
    {
        $logConfig = $config['analyzer_log'] ?? [];

        return new AnalyzerLogHandler(
            $logConfig['log_dir'] ?? storage_path('tombstone'),
            $logConfig['size_limit'] ?? null,
            null,
            $logConfig['use_locking'] ?? true,
        );
    }

    private function buildLogChannelHandler(array $config): PsrLoggerHandler
    {
        $channelConfig = $config['log_channel'] ?? [];
        $channel = $channelConfig['channel'] ?? 'tombstone';
        $level = $channelConfig['level'] ?? 'info';

        $logger = $this->app->make('log')->channel($channel);

        return new PsrLoggerHandler($logger, $level);
    }
}
```

**Step 4: Run test to verify it passes**

```bash
./vendor/bin/phpunit tests/TombstoneServiceProviderTest.php
```

Expected: All 5 tests PASS.

**Step 5: Commit**

```bash
git add src/TombstoneServiceProvider.php tests/TombstoneServiceProviderTest.php
git commit -m "feat: add TombstoneServiceProvider with handler setup and auto-discovery"
```

---

### Task 7: Create TombstoneReportCommand

**Files:**
- Create: `src/Console/TombstoneReportCommand.php`
- Create: `tests/TombstoneReportCommandTest.php`

**Context:** This is the most complex component. It wires scheb/tombstone's analyzer pipeline into an artisan command. The command needs to:

1. Build a `TombstoneIndex` by parsing source files for `tombstone()` calls
2. Build a `VampireIndex` by reading `.tombstone` log files
3. Match vampires to tombstones via `Processor`
4. Generate reports via `ConsoleReportGenerator` and optionally `HtmlReportGenerator`

Key classes used from scheb/tombstone:
- `Scheb\Tombstone\Analyzer\Stock\ParserTombstoneProvider::create(array $config, ConsoleOutputInterface $output)`
- `Scheb\Tombstone\Analyzer\Log\AnalyzerLogProvider::create(array $config, ConsoleOutputInterface $output)`
- Both `create()` methods expect a config array with this structure:
  ```php
  [
      'source_code' => ['root_directory' => '/path'],
      'tombstones' => ['parser' => ['excludes' => [], 'names' => ['*.php'], 'not_names' => [], 'function_names' => ['tombstone']]],
      'logs' => ['directory' => '/path/to/logs'],
      'report' => ['console' => true, 'html' => '/path/to/output'],
  ]
  ```
- `Scheb\Tombstone\Analyzer\Cli\ConsoleOutput` wraps `Symfony\Component\Console\Output\OutputInterface`

**Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Wprigollopes\LaravelTombstone\Tests;

use Orchestra\Testbench\TestCase;
use Wprigollopes\LaravelTombstone\TombstoneServiceProvider;

class TombstoneReportCommandTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [TombstoneServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('tombstone.enabled', true);
        $app['config']->set('tombstone.handler', 'analyzer_log');
        $app['config']->set('tombstone.analyzer_log.log_dir', storage_path('tombstone'));
        $app['config']->set('tombstone.root_directory', base_path());
    }

    public function testCommandExistsAndShowsHelp(): void
    {
        $this->artisan('tombstone:report', ['--help' => true])
            ->assertExitCode(0);
    }

    public function testCommandRunsWithNoLogs(): void
    {
        // Ensure the log directory exists but is empty
        $logDir = storage_path('tombstone');
        @mkdir($logDir, 0755, true);

        $this->artisan('tombstone:report')
            ->assertExitCode(0);
    }
}
```

**Step 2: Run test to verify it fails**

```bash
./vendor/bin/phpunit tests/TombstoneReportCommandTest.php
```

Expected: FAIL — class `TombstoneReportCommand` does not exist.

**Step 3: Write the implementation**

```php
<?php

declare(strict_types=1);

namespace Wprigollopes\LaravelTombstone\Console;

use Illuminate\Console\Command;
use Scheb\Tombstone\Analyzer\Cli\ConsoleOutput;
use Scheb\Tombstone\Analyzer\Log\AnalyzerLogProvider;
use Scheb\Tombstone\Analyzer\Log\LogCollector;
use Scheb\Tombstone\Analyzer\Matching\MethodNameStrategy;
use Scheb\Tombstone\Analyzer\Matching\PositionStrategy;
use Scheb\Tombstone\Analyzer\Matching\Processor;
use Scheb\Tombstone\Analyzer\Matching\VampireMatcher;
use Scheb\Tombstone\Analyzer\Model\TombstoneIndex;
use Scheb\Tombstone\Analyzer\Model\VampireIndex;
use Scheb\Tombstone\Analyzer\Report\Console\ConsoleReportGenerator;
use Scheb\Tombstone\Analyzer\Report\Html\HtmlReportGenerator;
use Scheb\Tombstone\Analyzer\Report\ReportExporter;
use Scheb\Tombstone\Analyzer\Stock\ParserTombstoneProvider;
use Scheb\Tombstone\Analyzer\Stock\TombstoneCollector;

class TombstoneReportCommand extends Command
{
    protected $signature = 'tombstone:report
        {--html : Generate an HTML report}
        {--html-output= : Directory for the HTML report}';

    protected $description = 'Analyze tombstones and generate a dead code report';

    public function handle(): int
    {
        $config = $this->buildAnalyzerConfig();
        $consoleOutput = new ConsoleOutput($this->getOutput());

        $this->info('Collecting tombstones from source code...');
        $tombstoneIndex = new TombstoneIndex();
        $tombstoneProvider = ParserTombstoneProvider::create($config, $consoleOutput);
        $tombstoneCollector = new TombstoneCollector([$tombstoneProvider], $tombstoneIndex);
        $tombstoneCollector->collectTombstones();

        $this->info('Collecting vampire logs...');
        $vampireIndex = new VampireIndex();
        $logDir = $config['logs']['directory'];
        if (is_dir($logDir)) {
            $logProvider = AnalyzerLogProvider::create($config, $consoleOutput);
            $logCollector = new LogCollector([$logProvider], $vampireIndex);
            $logCollector->collectLogs();
        }

        $this->info('Analyzing tombstones...');
        $processor = new Processor(new VampireMatcher([
            new MethodNameStrategy(),
            new PositionStrategy(),
        ]));
        $result = $processor->process($tombstoneIndex, $vampireIndex);

        $reportGenerators = [
            ConsoleReportGenerator::create($config, $consoleOutput),
        ];

        if ($this->option('html')) {
            $htmlOutput = $this->option('html-output')
                ?? config('tombstone.report.html_output')
                ?? storage_path('tombstone/report');

            $config['report']['html'] = $htmlOutput;

            if (! is_dir($htmlOutput)) {
                @mkdir($htmlOutput, 0755, true);
            }

            $reportGenerators[] = HtmlReportGenerator::create($config, $consoleOutput);
            $this->info("HTML report will be written to: {$htmlOutput}");
        }

        $exporter = new ReportExporter($consoleOutput, $reportGenerators);
        $exporter->generate($result);

        return self::SUCCESS;
    }

    private function buildAnalyzerConfig(): array
    {
        $tombstoneConfig = config('tombstone');

        return [
            'source_code' => [
                'root_directory' => $tombstoneConfig['root_directory'] ?? base_path(),
            ],
            'tombstones' => [
                'parser' => [
                    'excludes' => $tombstoneConfig['report']['source_excludes'] ?? ['vendor', 'node_modules'],
                    'names' => ['*.php'],
                    'not_names' => [],
                    'function_names' => ['tombstone'],
                ],
            ],
            'logs' => [
                'directory' => $tombstoneConfig['analyzer_log']['log_dir'] ?? storage_path('tombstone'),
            ],
            'report' => [
                'console' => true,
            ],
        ];
    }
}
```

**Step 4: Run test to verify it passes**

```bash
./vendor/bin/phpunit tests/TombstoneReportCommandTest.php
```

Expected: Both tests PASS.

**Step 5: Commit**

```bash
git add src/Console/TombstoneReportCommand.php tests/TombstoneReportCommandTest.php
git commit -m "feat: add tombstone:report artisan command with console and HTML output"
```

---

### Task 8: Integration test — end-to-end tombstone flow

**Files:**
- Create: `tests/IntegrationTest.php`

**Purpose:** Verify the full flow: configure graveyard → call `tombstone()` → flush → verify log file written.

**Step 1: Write the integration test**

```php
<?php

declare(strict_types=1);

namespace Wprigollopes\LaravelTombstone\Tests;

use Orchestra\Testbench\TestCase;
use Scheb\Tombstone\Logger\Graveyard\GraveyardInterface;
use Scheb\Tombstone\Logger\Graveyard\GraveyardRegistry;
use Wprigollopes\LaravelTombstone\TombstoneServiceProvider;

class IntegrationTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [TombstoneServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $logDir = storage_path('tombstone-test-' . uniqid());
        @mkdir($logDir, 0755, true);

        $app['config']->set('tombstone.enabled', true);
        $app['config']->set('tombstone.handler', 'analyzer_log');
        $app['config']->set('tombstone.buffer', true);
        $app['config']->set('tombstone.dedup.enabled', false);
        $app['config']->set('tombstone.analyzer_log.log_dir', $logDir);
        $app['config']->set('tombstone.root_directory', base_path());
    }

    public function testTombstoneCallCreatesLogFileAfterFlush(): void
    {
        $logDir = config('tombstone.analyzer_log.log_dir');

        // Verify graveyard is registered
        $graveyard = GraveyardRegistry::getGraveyard();
        $this->assertInstanceOf(GraveyardInterface::class, $graveyard);

        // Call tombstone
        tombstone('2024-01-01', 'integration-test');

        // Before flush, no files yet (buffered)
        $filesBefore = glob($logDir . '/*.tombstone');
        $this->assertEmpty($filesBefore);

        // Flush the graveyard
        $graveyard->flush();

        // After flush, log files should exist
        $filesAfter = glob($logDir . '/*.tombstone');
        $this->assertNotEmpty($filesAfter, 'Expected .tombstone log files after flush');

        // Verify file content is valid JSON per line
        $content = file_get_contents($filesAfter[0]);
        $lines = array_filter(explode("\n", trim($content)));
        foreach ($lines as $line) {
            $decoded = json_decode($line, true);
            $this->assertIsArray($decoded);
            $this->assertArrayHasKey('fn', $decoded);
        }

        // Cleanup
        array_map('unlink', glob($logDir . '/*.tombstone'));
        @rmdir($logDir);
    }

    public function testDisabledTombstoneDoesNotRegisterGraveyard(): void
    {
        $this->app['config']->set('tombstone.enabled', false);

        // Reset the global registry
        $reflection = new \ReflectionClass(GraveyardRegistry::class);
        $property = $reflection->getProperty('graveyard');
        $property->setAccessible(true);
        $property->setValue(null, null);

        // Re-register the provider with disabled config
        $provider = new TombstoneServiceProvider($this->app);
        $provider->register();

        $this->expectException(\Scheb\Tombstone\Logger\Graveyard\GraveyardNotSetException::class);
        GraveyardRegistry::getGraveyard();
    }
}
```

**Step 2: Run the integration test**

```bash
./vendor/bin/phpunit tests/IntegrationTest.php
```

Expected: All 2 tests PASS.

**Step 3: Commit**

```bash
git add tests/IntegrationTest.php
git commit -m "test: add end-to-end integration test for tombstone flow"
```

---

### Task 9: Run full test suite and finalize

**Step 1: Run all tests**

```bash
./vendor/bin/phpunit
```

Expected: All tests PASS.

**Step 2: Verify package auto-discovery works**

Check that `composer.json` has the correct `extra.laravel.providers` entry. This was done in Task 1.

**Step 3: Final commit if any fixes needed**

```bash
git add -A
git status
# Only commit if there are changes
```

**Step 4: Tag initial release**

```bash
git tag v0.1.0
```
