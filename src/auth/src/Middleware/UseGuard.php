<?php

declare(strict_types=1);

namespace Hypervel\Auth\Middleware;

use Closure;
use Hypervel\Contracts\Auth\Factory as Auth;
use Hypervel\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class UseGuard
{
    /**
     * Create a new middleware instance.
     */
    public function __construct(
        protected Auth $auth,
    ) {
    }

    /**
     * Select the guard that should be used for the current request.
     */
    public function handle(Request $request, Closure $next, string $guard): Response
    {
        $this->auth->shouldUse($guard);

        return $next($request);
    }
}
