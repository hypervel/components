<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Server\Middleware;

use Closure;
use Hypervel\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AddAuthorizationFramingHeaders
{
    /**
     * Prevent authorization pages from being embedded in another page.
     *
     * @param Closure(Request): Response $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $request->routeIs('passport.authorizations.*', 'passport.device', 'passport.device.authorizations.*')) {
            return $response;
        }

        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Content-Security-Policy', "frame-ancestors 'none'", false);

        return $response;
    }
}
