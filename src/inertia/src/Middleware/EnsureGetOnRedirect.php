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
     * Ensure redirects after PUT/PATCH/DELETE requests result in a
     * GET request by converting 302 responses to 303.
     */
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        if ($response->getStatusCode() === 302
            && $request->header(Header::INERTIA)
            && in_array($request->method(), ['PUT', 'PATCH', 'DELETE'])
        ) {
            $response->setStatusCode(303);
        }

        return $response;
    }
}
