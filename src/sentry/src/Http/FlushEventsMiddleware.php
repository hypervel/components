<?php

declare(strict_types=1);

namespace Hypervel\Sentry\Http;

use Closure;
use Hypervel\Http\Request;
use Hypervel\Sentry\State\RuntimeContextBoundary;
use Symfony\Component\HttpFoundation\Response;

/**
 * Open the outer request context whose deferred end flushes buffered telemetry.
 */
class FlushEventsMiddleware
{
    /**
     * Create a Sentry runtime context middleware.
     */
    public function __construct(
        protected RuntimeContextBoundary $runtimeContextBoundary,
    ) {
    }

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $this->runtimeContextBoundary->start();

        return $next($request);
    }
}
