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
