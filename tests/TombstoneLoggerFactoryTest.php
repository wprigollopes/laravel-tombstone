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
