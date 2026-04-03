<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Auth;

use Closure;

interface PasswordBroker
{
    /**
     * Constant representing a successfully sent reminder.
     */
    public const string RESET_LINK_SENT = 'passwords.sent';

    /**
     * Constant representing a successfully reset password.
     */
    public const string PASSWORD_RESET = 'passwords.reset';

    /**
     * Constant representing the user not found response.
     */
    public const string INVALID_USER = 'passwords.user';

    /**
     * Constant representing an invalid token.
     */
    public const string INVALID_TOKEN = 'passwords.token';

    /**
     * Constant representing a throttled reset attempt.
     */
    public const string RESET_THROTTLED = 'passwords.throttled';

    /**
     * Send a password reset link to a user.
     */
    public function sendResetLink(array $credentials, ?Closure $callback = null): string;

    /**
     * Reset the password for the given token.
     */
    public function reset(array $credentials, Closure $callback): mixed;
}
