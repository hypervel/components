<?php

declare(strict_types=1);

namespace Hypervel\Fortify\Actions;

use Closure;
use Hypervel\Auth\Events\Lockout;
use Hypervel\Fortify\Concerns\DispatchesEvents;
use Hypervel\Fortify\Contracts\LockoutResponse;
use Hypervel\Fortify\LoginRateLimiter;
use Hypervel\Http\Request;

class EnsureLoginIsNotThrottled
{
    use DispatchesEvents;

    /**
     * Create a new class instance.
     */
    public function __construct(
        protected LoginRateLimiter $limiter,
    ) {
    }

    /**
     * Handle the incoming request.
     */
    public function handle(Request $request, Closure $next): mixed
    {
        if (! $this->limiter->tooManyAttempts($request)) {
            return $next($request);
        }

        $this->dispatchIfListening(
            Lockout::class,
            static fn (): Lockout => new Lockout($request),
        );

        return app(LockoutResponse::class);
    }
}
