<?php

declare(strict_types=1);

namespace Hypervel\Auth;

use Hypervel\Contracts\Config\Repository;

final class PasswordConfirmation
{
    /**
     * Get the session key holding the password confirmation timestamp for the guard.
     */
    public static function sessionKey(string $guard): string
    {
        return "auth.password_confirmed_at_{$guard}";
    }

    /**
     * Get the password confirmation timeout in seconds for the guard.
     *
     * An explicit override wins, followed by the guard declaration, then
     * the application-wide timeout.
     */
    public static function timeout(Repository $config, string $guard, string|int|null $override = null): int
    {
        if ($override !== null) {
            return (int) $override;
        }

        $key = "auth.guards.{$guard}.password_timeout";

        // A declared null inherits the application-wide timeout. Missing members
        // fall through so the typed getter names the incomplete guard record.
        if ($config->has($key) && $config->get($key) === null) {
            return $config->integer('auth.password_timeout');
        }

        return $config->integer($key);
    }
}
