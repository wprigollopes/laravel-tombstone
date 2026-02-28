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
        $app['config']->set('tombstone.root_directory', __DIR__ . '/fixtures/source');
    }

    public function testCommandExistsAndShowsHelp(): void
    {
        $this->artisan('tombstone:report', ['--help' => true])
            ->assertExitCode(0);
    }

    public function testCommandRunsWithNoLogs(): void
    {
        $logDir = storage_path('tombstone');
        @mkdir($logDir, 0755, true);

        $this->artisan('tombstone:report')
            ->assertExitCode(0);
    }
}
