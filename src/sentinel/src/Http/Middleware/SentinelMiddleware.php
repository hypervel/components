<?php

declare(strict_types=1);

namespace Hypervel\Sentinel\Http\Middleware;

use Closure;
use Hypervel\Http\Request;
use Hypervel\Sentinel\Sentinel;
use Symfony\Component\HttpFoundation\Response;

class SentinelMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param Closure(Request): Response $next
     */
    public function handle(Request $request, Closure $next, ?string $driver = null): Response
    {
        abort_unless(Sentinel::driverOrFallback($driver)->authorize($request), 401);

        return $next($request);
    }
}
