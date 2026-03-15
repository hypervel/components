<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Testing\Stubs;

use Closure;
use Hypervel\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class FakeMiddleware
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }
}
