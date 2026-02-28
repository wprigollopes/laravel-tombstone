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
