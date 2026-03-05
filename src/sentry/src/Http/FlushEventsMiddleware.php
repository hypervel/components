<?php

declare(strict_types=1);

namespace Hypervel\Sentry\Http;

use Closure;
use Hypervel\Http\Request;
use Hypervel\Sentry\Integrations\Integration;
use Symfony\Component\HttpFoundation\Response;

class FlushEventsMiddleware
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    /**
     * Perform cleanup after the response has been sent.
     */
    public function terminate(Request $request, Response $response): void
    {
        Integration::flushEvents();
    }
}
