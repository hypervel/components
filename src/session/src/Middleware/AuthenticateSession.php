<?php

declare(strict_types=1);

namespace Hypervel\Session\Middleware;

use BadMethodCallException;
use Closure;
use Hypervel\Auth\AuthenticationException;
use Hypervel\Contracts\Auth\Factory as AuthFactory;
use Hypervel\Contracts\Session\Middleware\AuthenticatesSessions;
use Hypervel\Http\Request;

class AuthenticateSession implements AuthenticatesSessions
{
    /**
     * The callback that should be used to generate the authentication redirect path.
     *
     * @var null|callable
     */
    protected static $redirectToCallback;

    /**
     * Create a new middleware instance.
     */
    public function __construct(
        protected AuthFactory $auth,
    ) {
    }

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): mixed
    {
        if (! $request->hasSession() || ! $request->user() || ! $request->user()->getAuthPassword()) {
            return $next($request);
        }

        if ($this->guard()->viaRemember()) { // @phpstan-ignore method.notFound (proxied to SessionGuard via AuthManager::__call)
            $passwordHashFromCookie = explode('|', $request->cookies->get($this->guard()->getRecallerName()))[2] ?? null; // @phpstan-ignore method.notFound

            if (! $passwordHashFromCookie
                || ! $this->validatePasswordHash($request->user()->getAuthPassword(), $passwordHashFromCookie)) {
                $this->logout($request);
            }
        }

        if (! $request->session()->has('password_hash_' . $this->auth->getDefaultDriver())) { // @phpstan-ignore method.notFound (proxied via AuthManager::__call)
            $this->storePasswordHashInSession($request);
        }

        $sessionPasswordHash = $request->session()->get('password_hash_' . $this->auth->getDefaultDriver()); // @phpstan-ignore method.notFound

        if (! $this->validatePasswordHash($request->user()->getAuthPassword(), $sessionPasswordHash)) {
            $this->logout($request);
        }

        return tap($next($request), function () use ($request) {
            if (! is_null($this->guard()->user())) { // @phpstan-ignore method.notFound
                $this->storePasswordHashInSession($request);
            }
        });
    }

    /**
     * Store the user's current password hash in the session.
     */
    protected function storePasswordHashInSession(Request $request): void
    {
        if (! $request->user()) {
            return;
        }

        $passwordHash = $request->user()->getAuthPassword();

        try {
            $passwordHash = $this->guard()->hashPasswordForCookie($passwordHash); // @phpstan-ignore method.notFound
        } catch (BadMethodCallException) {
        }

        $request->session()->put([
            'password_hash_' . $this->auth->getDefaultDriver() => $passwordHash, // @phpstan-ignore method.notFound
        ]);
    }

    /**
     * Validate the password hash against the stored value.
     */
    protected function validatePasswordHash(string $passwordHash, string $storedValue): bool
    {
        try {
            // Try new HMAC format first, then fall back to raw password hash format for backward compatibility
            return hash_equals($this->guard()->hashPasswordForCookie($passwordHash), $storedValue) // @phpstan-ignore method.notFound
                || hash_equals($passwordHash, $storedValue);
        } catch (BadMethodCallException) {
            return hash_equals($passwordHash, $storedValue);
        }
    }

    /**
     * Log the user out of the application.
     *
     * @throws AuthenticationException
     */
    protected function logout(Request $request): void
    {
        $this->guard()->logoutCurrentDevice(); // @phpstan-ignore method.notFound

        $request->session()->flush();

        throw new AuthenticationException(
            'Unauthenticated.',
            [$this->auth->getDefaultDriver()], // @phpstan-ignore method.notFound
            $this->redirectTo($request) // @phpstan-ignore method.notFound
        );
    }

    /**
     * Get the guard instance that should be used by the middleware.
     */
    protected function guard(): AuthFactory
    {
        return $this->auth;
    }

    /**
     * Get the path the user should be redirected to when their session is not authenticated.
     */
    protected function redirectTo(Request $request): ?string
    {
        if (static::$redirectToCallback) {
            return call_user_func(static::$redirectToCallback, $request);
        }

        return null;
    }

    /**
     * Specify the callback that should be used to generate the redirect path.
     */
    public static function redirectUsing(callable $redirectToCallback): void
    {
        static::$redirectToCallback = $redirectToCallback;
    }

    /**
     * Flush the state of the middleware.
     */
    public static function flushState(): void
    {
        static::$redirectToCallback = null;
    }
}
