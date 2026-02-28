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
        $reflection = new \ReflectionClass(GraveyardRegistry::class);
        $property = $reflection->getProperty('graveyard');
        $property->setAccessible(true);
        $property->setValue(null, null);

        $middleware = new FlushTombstones();

        $middleware->terminate(
            Request::create('/test'),
            new Response(),
        );

        $this->assertTrue(true);
    }
}
