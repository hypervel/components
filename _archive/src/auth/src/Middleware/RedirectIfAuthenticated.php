<?php

declare(strict_types=1);

namespace Hypervel\Auth\Middleware;

/**
 * @TODO Port fully from Laravel once the auth package is aligned with Laravel.
 * This requires the full handle() method with Auth/Route facade usage and
 * defaultRedirectUri() logic. Currently only the static configuration methods
 * exist so that Configuration\Middleware can reference this class without errors.
 */
class RedirectIfAuthenticated
{
    /**
     * The callback that should be used to generate the authentication redirect path.
     *
     * @var null|callable
     */
    protected static $redirectToCallback;

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
