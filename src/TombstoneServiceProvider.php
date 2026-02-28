<?php

declare(strict_types=1);

namespace Wprigollopes\LaravelTombstone;

use Illuminate\Contracts\Foundation\Application;
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

        $this->app->singleton(GraveyardInterface::class, function (Application $app): GraveyardInterface {
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
                $kernel->pushMiddleware(FlushTombstones::class);
            }
        }

        if ($this->app->runningInConsole()) {
            $this->commands([
                TombstoneReportCommand::class,
            ]);
        }
    }

    /** @param array<string, mixed> $config */
    private function buildHandler(array $config): HandlerInterface
    {
        return match ($config['handler'] ?? 'analyzer_log') {
            'log_channel' => $this->buildLogChannelHandler($config),
            default => $this->buildAnalyzerLogHandler($config),
        };
    }

    /** @param array<string, mixed> $config */
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

    /** @param array<string, mixed> $config */
    private function buildLogChannelHandler(array $config): PsrLoggerHandler
    {
        $channelConfig = $config['log_channel'] ?? [];
        $channel = $channelConfig['channel'] ?? 'tombstone';
        $level = $channelConfig['level'] ?? 'info';

        $logger = $this->app->make('log')->channel($channel);

        return new PsrLoggerHandler($logger, $level);
    }
}
