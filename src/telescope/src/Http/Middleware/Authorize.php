<?php

declare(strict_types=1);

namespace Hypervel\Telescope\Http\Middleware;

use Closure;
use Hypervel\Http\Request;
use Hypervel\Telescope\Telescope;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

class Authorize
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! Telescope::check($request)) {
            throw new HttpException(403);
        }

        return $next($request);
    }
}
