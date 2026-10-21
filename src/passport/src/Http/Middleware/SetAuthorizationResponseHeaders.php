<?php

declare(strict_types=1);

namespace Hypervel\Passport\Http\Middleware;

use Closure;
use Hypervel\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetAuthorizationResponseHeaders
{
    /**
     * Prevent authorization pages from being cached or disclosing their URL.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // RFC 9700 section 4.2 also covers state leaking from authorization request URLs.
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
