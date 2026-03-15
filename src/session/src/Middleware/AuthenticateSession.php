<?php

declare(strict_types=1);

namespace Hypervel\Session\Middleware;

use Hypervel\Contracts\Session\Middleware\AuthenticatesSessions;

/**
 * @TODO Port fully from Laravel once the auth and session packages are aligned with Laravel.
 * This requires SessionGuard methods (viaRemember, getRecallerName, logoutCurrentDevice,
 * hashPasswordForCookie) and the full handle() middleware logic. Currently only the static
 * configuration methods exist so that Configuration\Middleware can reference this class
 * without errors.
 */
class AuthenticateSession implements AuthenticatesSessions
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
