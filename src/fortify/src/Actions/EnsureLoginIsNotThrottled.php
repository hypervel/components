<?php

declare(strict_types=1);

namespace Hypervel\Fortify\Actions;

use Closure;
use Hypervel\Auth\Events\Lockout;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Fortify\Concerns\DispatchesEvents;
use Hypervel\Fortify\Contracts\LockoutResponse;
use Hypervel\Fortify\LoginRateLimiter;
use Hypervel\Http\Request;

class EnsureLoginIsNotThrottled
{
    use DispatchesEvents;

    public function __construct(
        protected readonly LoginRateLimiter $limiter,
        protected readonly Dispatcher $events,
        protected readonly Container $container,
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
            $this->events,
            Lockout::class,
            static fn (): Lockout => new Lockout($request),
        );

        return $this->container->make(LockoutResponse::class);
    }
}
