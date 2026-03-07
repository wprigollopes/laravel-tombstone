<?php

declare(strict_types=1);

$functionFile = dirname(__DIR__).'/vendor/scheb/tombstone/src/logger/tombstone-function.php';

if (! file_exists($functionFile)) {
    // Installed as a dependency — resolve from project root vendor dir
    $functionFile = dirname(__DIR__, 3).'/scheb/tombstone/src/logger/tombstone-function.php';
}

if (file_exists($functionFile)) {
    require_once $functionFile;
}
