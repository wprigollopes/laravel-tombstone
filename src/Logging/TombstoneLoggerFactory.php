<?php

declare(strict_types=1);

namespace Wprigollopes\LaravelTombstone\Logging;

use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;

class TombstoneLoggerFactory
{
    /** @param array<string, mixed> $config */
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
