<?php

declare(strict_types=1);

namespace Wprigollopes\LaravelTombstone\Middleware;

use Closure;
use Illuminate\Http\Request;
use Scheb\Tombstone\Logger\Graveyard\GraveyardRegistry;
use Symfony\Component\HttpFoundation\Response;

class FlushTombstones
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        try {
            GraveyardRegistry::getGraveyard()->flush();
        } catch (\Throwable) {
            // Tombstone flush failures must never break the application
        }
    }
}
