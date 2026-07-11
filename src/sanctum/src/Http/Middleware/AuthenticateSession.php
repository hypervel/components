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

        $guards = [];

        foreach ($this->sanctumSessionGuards() as $name) {
            $guard = $this->auth->guard($name);

            if ($guard instanceof SessionGuard) {
                $guards[$name] = $guard;
            }
        }

        $authenticatedGuards = array_filter($guards, fn (SessionGuard $guard): bool => $guard->check());

        if ($authenticatedGuards === []) {
            return $next($request);
        }

        $shouldLogout = [];

        foreach ($authenticatedGuards as $driver => $guard) {
            $hasStaleRecallerHash = $guard->viaRemember()
                && ! $this->validateRecallerPasswordHash($request, $guard);
            $hasStaleSessionHash = $request->session()->has('password_hash_' . $driver)
                && ! $this->validatePasswordHash(
                    $guard,
                    $guard->user()->getAuthPassword(),
                    $request->session()->get('password_hash_' . $driver)
                );

            if ($hasStaleRecallerHash || $hasStaleSessionHash) {
                $shouldLogout[$driver] = $guard;
            }
        }

        if ($shouldLogout !== []) {
            foreach ($shouldLogout as $guard) {
                $guard->logoutCurrentDevice();
            }

            $request->session()->flush();

            throw new AuthenticationException('Unauthenticated.', [...array_keys($shouldLogout), 'sanctum']);
        }

        $response = $next($request);

        foreach ($guards as $name => $guard) {
            if ($guard->hasUser()) {
                $this->storePasswordHashInSession($request, $name, $guard);
            }
        }

        return $response;
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
    protected function storePasswordHashInSession(Request $request, string $guard, SessionGuard $guardInstance): void
    {
        $request->session()->put([
            "password_hash_{$guard}" => $guardInstance->hashPasswordForCookie($guardInstance->user()->getAuthPassword()),
        ]);
    }

    /**
     * Validate the password hash against the stored value.
     *
     * Only HMAC artifacts are valid; Hypervel has no released raw-hash
     * session artifacts to accept.
     */
    protected function validatePasswordHash(SessionGuard $guard, ?string $passwordHash, mixed $storedValue): bool
    {
        return is_string($storedValue)
            && hash_equals($guard->hashPasswordForCookie($passwordHash), $storedValue);
    }

    /**
     * Validate the remembered-login password hash against the guard's current user.
     */
    protected function validateRecallerPasswordHash(Request $request, SessionGuard $guard): bool
    {
        $recaller = $request->cookies->get($guard->getRecallerName());

        if (! is_string($recaller)) {
            return false;
        }

        return $this->validatePasswordHash(
            $guard,
            $guard->user()->getAuthPassword(),
            explode('|', $recaller)[2] ?? null
        );
    }
}
