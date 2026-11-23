<?php

declare(strict_types=1);

namespace Hypervel\Inertia\Middleware;

use Closure;
use Hypervel\Http\Request;
use Hypervel\Inertia\Support\Header;
use Symfony\Component\HttpFoundation\Response;

class EnsureGetOnRedirect
{
    /**
     * Ensure redirects after PUT/PATCH/DELETE/QUERY requests result in a
     * GET request by converting 302 responses to 303.
     */
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        // Unlike upstream, QUERY is included, matching the Inertia middleware's own conversion.
        if ($response->getStatusCode() === 302
            && $request->header(Header::INERTIA)
            && in_array($request->method(), ['PUT', 'PATCH', 'DELETE', 'QUERY'], true)
        ) {
            $response->setStatusCode(303);
        }

        return $response;
    }
}
