<?php

declare(strict_types=1);

namespace Hypervel\Support\Facades;

use Hypervel\Contracts\Auth\PasswordBroker;

/**
 * @method static \Hypervel\Contracts\Auth\PasswordBroker broker(string|null $name = null)
 * @method static string getDefaultDriver()
 * @method static void setDefaultDriver(string $name)
 * @method static string sendResetLink(array $credentials, \Closure|null $callback = null)
 * @method static mixed reset(array $credentials, \Closure $callback)
 * @method static \Hypervel\Contracts\Auth\CanResetPassword|null getUser(array $credentials)
 * @method static string createToken(\Hypervel\Contracts\Auth\CanResetPassword $user)
 * @method static void deleteToken(\Hypervel\Contracts\Auth\CanResetPassword $user)
 * @method static bool tokenExists(\Hypervel\Contracts\Auth\CanResetPassword $user, string $token)
 * @method static \Hypervel\Auth\Passwords\TokenRepositoryInterface getRepository()
 *
 * @see \Hypervel\Auth\Passwords\PasswordBrokerManager
 * @see \Hypervel\Auth\Passwords\PasswordBroker
 */
class Password extends Facade
{
    /**
     * Constant representing a successfully sent password reset email.
     */
    public const string ResetLinkSent = PasswordBroker::RESET_LINK_SENT;

    /**
     * Constant representing a successfully reset password.
     */
    public const string PasswordReset = PasswordBroker::PASSWORD_RESET;

    /**
     * Constant indicating the user could not be found when attempting a password reset.
     */
    public const string InvalidUser = PasswordBroker::INVALID_USER;

    /**
     * Constant representing an invalid password reset token.
     */
    public const string InvalidToken = PasswordBroker::INVALID_TOKEN;

    /**
     * Constant representing a throttled password reset attempt.
     */
    public const string ResetThrottled = PasswordBroker::RESET_THROTTLED;

    public const string RESET_LINK_SENT = PasswordBroker::RESET_LINK_SENT;

    public const string PASSWORD_RESET = PasswordBroker::PASSWORD_RESET;

    public const string INVALID_USER = PasswordBroker::INVALID_USER;

    public const string INVALID_TOKEN = PasswordBroker::INVALID_TOKEN;

    public const string RESET_THROTTLED = PasswordBroker::RESET_THROTTLED;

    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return 'auth.password';
    }
}
