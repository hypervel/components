<?php

declare(strict_types=1);

namespace Hypervel\Sentry\Http;

use Closure;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Http\Request;
use Hypervel\Sentry\Integration;
use Symfony\Component\HttpFoundation\Response;

class FlushEventsMiddleware
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        Coroutine::defer(static function (): void {
            Integration::flushEvents();
        });

        return $next($request);
    }
}
