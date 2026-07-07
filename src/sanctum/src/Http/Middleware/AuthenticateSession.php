<?php

declare(strict_types=1);

namespace Hypervel\Sanctum\Http\Middleware;

use Closure;
use Hypervel\Auth\AuthenticationException;
use Hypervel\Auth\SessionGuard;
use Hypervel\Contracts\Auth\Factory as AuthFactory;
use Hypervel\Contracts\Config\Repository;
use Hypervel\Contracts\Session\Middleware\AuthenticatesSessions;
use Hypervel\Http\Request;
use Hypervel\Support\Collection;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateSession implements AuthenticatesSessions
{
    /**
     * Create a new middleware instance.
     */
    public function __construct(
        protected AuthFactory $auth,
        protected Repository $config,
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

        $guards = Collection::make($this->sanctumSessionGuards())
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
                /** @var SessionGuard $guard */
                $guard->logout();
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
            return $this->auth->guard($guard)->hasUser();
        });
    }

    /**
     * Get the session guards declared by the application's sanctum guards.
     *
     * The union across every sanctum-driver guard entry: password-hash
     * invalidation applies to any session guard participating in stateful
     * sanctum authentication anywhere in the application.
     */
    protected function sanctumSessionGuards(): array
    {
        $sessionGuards = [];

        foreach ($this->config->array('auth.guards', []) as $guard) {
            if (! is_array($guard) || ($guard['driver'] ?? null) !== 'sanctum') {
                continue;
            }

            $declared = $guard['session_guards'] ?? null;

            if (! is_array($declared)) {
                continue;
            }

            $sessionGuards = [
                ...$sessionGuards,
                ...array_filter($declared, static fn (mixed $guard): bool => is_string($guard) && $guard !== ''),
            ];
        }

        return array_values(array_unique($sessionGuards));
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
