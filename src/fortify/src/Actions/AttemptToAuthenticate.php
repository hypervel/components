<?php

declare(strict_types=1);

namespace Hypervel\Fortify\Actions;

use Closure;
use Hypervel\Auth\Events\Failed;
use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Fortify\Concerns\DispatchesEvents;
use Hypervel\Fortify\Fortify;
use Hypervel\Fortify\LoginRateLimiter;
use Hypervel\Http\Request;
use Hypervel\Validation\ValidationException;

class AttemptToAuthenticate
{
    use DispatchesEvents;

    public function __construct(
        protected readonly LoginRateLimiter $limiter,
        protected readonly Dispatcher $events,
    ) {
    }

    /**
     * Handle the incoming request.
     *
     * @throws ValidationException
     */
    public function handle(Request $request, Closure $next): mixed
    {
        if (Fortify::authenticateUsingCallback() instanceof Closure) {
            return $this->handleUsingCustomCallback($request, $next);
        }

        if (Fortify::guard()->attempt(
            $request->only(Fortify::username(), 'password'),
            $request->boolean('remember'),
        )) {
            return $next($request);
        }

        $this->throwFailedAuthenticationException($request);
    }

    /**
     * Attempt to authenticate using a custom callback.
     *
     * @throws ValidationException
     */
    protected function handleUsingCustomCallback(Request $request, Closure $next): mixed
    {
        $callback = Fortify::authenticateUsingCallback();
        $user = $callback instanceof Closure ? $callback($request) : null;

        if (! $user instanceof Authenticatable) {
            $this->fireFailedEvent($request);

            $this->throwFailedAuthenticationException($request);
        }

        Fortify::guard()->login($user, $request->boolean('remember'));

        return $next($request);
    }

    /**
     * Throw a failed authentication validation exception.
     *
     * @throws ValidationException
     */
    protected function throwFailedAuthenticationException(Request $request): never
    {
        $this->limiter->increment($request);

        throw ValidationException::withMessages([
            Fortify::username() => [trans('auth.failed')],
        ]);
    }

    /**
     * Fire the failed authentication attempt event with the given arguments.
     */
    protected function fireFailedEvent(Request $request): void
    {
        $this->dispatchIfListening(
            $this->events,
            Failed::class,
            static fn (): Failed => new Failed(Fortify::guardName(), null, [
                Fortify::username() => $request->{Fortify::username()},
                'password' => $request->input('password'),
            ]),
        );
    }
}
