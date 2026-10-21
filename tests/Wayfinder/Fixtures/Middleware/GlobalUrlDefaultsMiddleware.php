<?php

declare(strict_types=1);

namespace Hypervel\Tests\Wayfinder\Fixtures\Middleware;

use Closure;
use Hypervel\Http\Request;
use Hypervel\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

class GlobalUrlDefaultsMiddleware
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        URL::defaults([
            'locale' => 'global',
        ]);

        return $next($request);
    }
}
