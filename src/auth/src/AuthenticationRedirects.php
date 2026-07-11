<?php

declare(strict_types=1);

namespace Hypervel\Auth;

use Hypervel\Auth\Middleware\Authenticate;
use Hypervel\Auth\Middleware\RedirectIfAuthenticated;
use Hypervel\Session\Middleware\AuthenticateSession;

/**
 * Single source of truth for boot-time auth redirect wiring.
 *
 * Configures the worker-lifetime redirect callbacks on the auth/guest
 * middleware and the authentication exception; it does not resolve or issue
 * redirects at runtime.
 */
class AuthenticationRedirects
{
    /**
     * Configure where guests are redirected by the "auth" middleware.
     *
     * Boot-only. The callback persists in authentication middleware and
     * exception static properties for the worker lifetime and affects every
     * subsequent unauthenticated or session-mismatch request.
     */
    public static function redirectGuestsTo(callable|string $redirect): void
    {
        self::redirectTo(guests: $redirect);
    }

    /**
     * Configure where users are redirected by the "guest" middleware.
     *
     * Boot-only. The callback persists in the guest middleware static property
     * for the worker lifetime and affects every subsequent already-authenticated
     * guest-route request.
     */
    public static function redirectUsersTo(callable|string $redirect): void
    {
        self::redirectTo(users: $redirect);
    }

    /**
     * Configure where users are redirected by the authentication and guest middleware.
     *
     * Boot-only. The callbacks persist in authentication middleware and
     * exception static properties for the worker lifetime and affect every
     * subsequent matching request.
     */
    public static function redirectTo(callable|string|null $guests = null, callable|string|null $users = null): void
    {
        $guests = is_string($guests) ? fn () => $guests : $guests;
        $users = is_string($users) ? fn () => $users : $users;

        if ($guests) {
            Authenticate::redirectUsing($guests);
            AuthenticateSession::redirectUsing($guests);
            AuthenticationException::redirectUsing($guests);
        }

        if ($users) {
            RedirectIfAuthenticated::redirectUsing($users);
        }
    }
}
