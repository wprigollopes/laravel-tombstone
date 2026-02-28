<?php

declare(strict_types=1);

namespace Wprigollopes\LaravelTombstone\Tests;

use Orchestra\Testbench\TestCase;
use Scheb\Tombstone\Logger\Graveyard\GraveyardInterface;
use Scheb\Tombstone\Logger\Graveyard\GraveyardRegistry;
use Wprigollopes\LaravelTombstone\TombstoneServiceProvider;

class IntegrationTest extends TestCase
{
    private string $logDir;

    protected function getPackageProviders($app): array
    {
        return [TombstoneServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $this->logDir = storage_path('tombstone-test-' . uniqid());
        @mkdir($this->logDir, 0755, true);

        $app['config']->set('tombstone.enabled', true);
        $app['config']->set('tombstone.handler', 'analyzer_log');
        $app['config']->set('tombstone.buffer', true);
        $app['config']->set('tombstone.dedup.enabled', false);
        $app['config']->set('tombstone.analyzer_log.log_dir', $this->logDir);
        $app['config']->set('tombstone.root_directory', base_path());
    }

    protected function tearDown(): void
    {
        $files = glob($this->logDir . '/*.tombstone') ?: [];
        array_map('unlink', $files);
        @rmdir($this->logDir);

        parent::tearDown();
    }

    public function testTombstoneCallCreatesLogFileAfterFlush(): void
    {
        $graveyard = GraveyardRegistry::getGraveyard();
        $this->assertInstanceOf(GraveyardInterface::class, $graveyard);

        tombstone('2024-01-01', 'integration-test');

        $filesBefore = glob($this->logDir . '/*.tombstone');
        $this->assertEmpty($filesBefore);

        $graveyard->flush();

        $filesAfter = glob($this->logDir . '/*.tombstone');
        $this->assertNotEmpty($filesAfter, 'Expected .tombstone log files after flush');

        $content = file_get_contents($filesAfter[0]);
        $lines = array_filter(explode("\n", trim($content)));
        foreach ($lines as $line) {
            $decoded = json_decode($line, true);
            $this->assertIsArray($decoded);
            $this->assertArrayHasKey('fn', $decoded);
        }
    }

    public function testDisabledTombstoneDoesNotRegisterGraveyard(): void
    {
        $this->app['config']->set('tombstone.enabled', false);

        $reflection = new \ReflectionClass(GraveyardRegistry::class);
        $property = $reflection->getProperty('graveyard');
        $property->setAccessible(true);
        $property->setValue(null, null);

        $provider = new TombstoneServiceProvider($this->app);
        $provider->register();

        $this->expectException(\Scheb\Tombstone\Logger\Graveyard\GraveyardNotSetException::class);
        GraveyardRegistry::getGraveyard();
    }
}
