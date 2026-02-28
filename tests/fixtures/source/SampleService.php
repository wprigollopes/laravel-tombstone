<?php

declare(strict_types=1);

namespace App\Services;

class SampleService
{
    public function oldMethod(): void
    {
        tombstone('2024-01-01');
    }
}
