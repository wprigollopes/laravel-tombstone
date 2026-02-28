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
        $reflection = new \ReflectionClass(GraveyardRegistry::class);
        $property = $reflection->getProperty('graveyard');
        $property->setAccessible(true);
        $property->setValue(null, null);

        $this->app['config']->set('tombstone.enabled', false);

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
