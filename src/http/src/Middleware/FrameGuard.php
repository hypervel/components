<?php

declare(strict_types=1);

namespace Hypervel\Http\Middleware;

use Closure;
use Hypervel\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class FrameGuard
{
    /**
     * Handle the given request and get the response.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $response->headers->has('X-Frame-Options')) {
            $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        }

        return $response;
    }
}
