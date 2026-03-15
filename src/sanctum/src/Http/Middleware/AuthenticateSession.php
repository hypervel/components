<?php

declare(strict_types=1);

namespace Hypervel\Sanctum\Http\Middleware;

use Closure;
use Hypervel\Auth\AuthenticationException;
use Hypervel\Auth\Guards\SessionGuard;
use Hypervel\Contracts\Auth\Factory as AuthFactory;
use Hypervel\Contracts\Session\Middleware\AuthenticatesSessions;
use Hypervel\Http\Request;
use Hypervel\Support\Arr;
use Hypervel\Support\Collection;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateSession implements AuthenticatesSessions
{
    /**
     * Create a new middleware instance.
     */
    public function __construct(
        protected AuthFactory $auth,
    ) {
    }

    /**
     * Handle an incoming request.
     *
     * @throws AuthenticationException
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->hasSession()) {
            return $next($request);
        }

        $guards = Collection::make(Arr::wrap(config('sanctum.guard')))
            ->mapWithKeys(fn ($guard) => [$guard => $this->auth->guard($guard)])
            ->filter(fn ($guard) => $guard instanceof SessionGuard);

        // Get the authenticated user from the guards
        $user = null;
        foreach ($guards as $guard) {
            if ($guard->check()) {
                $user = $guard->user();
                break;
            }
        }

        if (! $user) {
            return $next($request);
        }

        $shouldLogout = $guards->filter(
            fn (mixed $guard, string $driver) => $request->session()->has('password_hash_' . $driver)
        )->filter(
            fn (mixed $guard, string $driver) => $request->session()->get('password_hash_' . $driver)
                                    !== $user->getAuthPassword()
        );

        if ($shouldLogout->isNotEmpty()) {
            $shouldLogout->each(function ($guard) {
                if (method_exists($guard, 'logout')) {
                    $guard->logout();
                }
            });

            $request->session()->flush();

            throw new AuthenticationException('Unauthenticated.', [...$shouldLogout->keys()->all(), 'sanctum']);
        }

        // Store password hash after successful request
        $response = $next($request);

        if (! is_null($guard = $this->getFirstGuardWithUser($guards->keys()))) {
            $this->storePasswordHashInSession($request, $guard);
        }

        return $response;
    }

    /**
     * Get the first authentication guard that has a user.
     */
    protected function getFirstGuardWithUser(Collection $guards): ?string
    {
        return $guards->first(function (string $guard) {
            $guardInstance = $this->auth->guard($guard);

            return method_exists($guardInstance, 'hasUser')
                   && $guardInstance->hasUser();
        });
    }

    /**
     * Store the user's current password hash in the session.
     */
    protected function storePasswordHashInSession(Request $request, string $guard): void
    {
        $request->session()->put([
            "password_hash_{$guard}" => $this->auth->guard($guard)->user()->getAuthPassword(),
        ]);
    }
}
