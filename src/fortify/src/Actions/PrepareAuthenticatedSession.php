<?php

declare(strict_types=1);

namespace Hypervel\Fortify\Actions;

use Closure;
use Hypervel\Fortify\LoginRateLimiter;
use Hypervel\Http\Request;

class PrepareAuthenticatedSession
{
    public function __construct(
        private readonly LoginRateLimiter $limiter,
    ) {
    }

    /**
     * Handle the incoming request.
     */
    public function handle(Request $request, Closure $next): mixed
    {
        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        $this->limiter->clear($request);

        return $next($request);
    }
}
