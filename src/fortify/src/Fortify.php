<?php

declare(strict_types=1);

namespace Hypervel\Fortify;

use Closure;
use Hypervel\Container\Container;
use Hypervel\Contracts\Auth\Factory as AuthFactory;
use Hypervel\Contracts\Auth\StatefulGuard;
use Hypervel\Contracts\Config\Repository as Config;
use Hypervel\Contracts\Encryption\Encrypter as EncrypterContract;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Fortify\Contracts\ConfirmPasswordViewResponse;
use Hypervel\Fortify\Contracts\CreatesNewUsers;
use Hypervel\Fortify\Contracts\LoginViewResponse;
use Hypervel\Fortify\Contracts\RedirectsIfTwoFactorAuthenticatable;
use Hypervel\Fortify\Contracts\RegisterViewResponse;
use Hypervel\Fortify\Contracts\RequestPasswordResetLinkViewResponse;
use Hypervel\Fortify\Contracts\ResetPasswordViewResponse;
use Hypervel\Fortify\Contracts\ResetsUserPasswords;
use Hypervel\Fortify\Contracts\TwoFactorChallengeViewResponse;
use Hypervel\Fortify\Contracts\UpdatesUserPasswords;
use Hypervel\Fortify\Contracts\UpdatesUserProfileInformation;
use Hypervel\Fortify\Contracts\VerifyEmailViewResponse;
use Hypervel\Fortify\Http\Responses\SimpleViewResponse;
use Hypervel\Http\Request;
use RuntimeException;

class Fortify
{
    public const PASSWORD_UPDATED = 'password-updated';

    public const PROFILE_INFORMATION_UPDATED = 'profile-information-updated';

    public const RECOVERY_CODES_GENERATED = 'recovery-codes-generated';

    public const TWO_FACTOR_AUTHENTICATION_CONFIRMED = 'two-factor-authentication-confirmed';

    public const TWO_FACTOR_AUTHENTICATION_DISABLED = 'two-factor-authentication-disabled';

    public const TWO_FACTOR_AUTHENTICATION_ENABLED = 'two-factor-authentication-enabled';

    public const VERIFICATION_LINK_SENT = 'verification-link-sent';

    private const DEFAULT_REGISTERS_ROUTES = true;

    private static ?Closure $authenticateThroughCallback = null;

    private static ?Closure $authenticateUsingCallback = null;

    private static ?Closure $confirmPasswordsUsingCallback = null;

    private static bool $registersRoutes = self::DEFAULT_REGISTERS_ROUTES;

    private static ?EncrypterContract $encrypter = null;

    /** @var array<string, Closure(Request): (null|string)> */
    private static array $redirectUsingCallbacks = [];

    /**
     * Get the username used for authentication.
     */
    public static function username(): string
    {
        return self::config()->string('fortify.username');
    }

    /**
     * Get the name of the email address request variable / field.
     */
    public static function email(): string
    {
        return self::config()->string('fortify.email');
    }

    /**
     * Get a completion redirect path for a specific feature.
     */
    public static function redirects(string $redirect, mixed $default = null, ?Request $request = null): string
    {
        if ($request !== null && isset(self::$redirectUsingCallbacks[$redirect])) {
            $resolved = (self::$redirectUsingCallbacks[$redirect])($request);

            if (is_string($resolved) && $resolved !== '') {
                return $resolved;
            }
        }

        return (string) (self::config()->get("fortify.redirects.{$redirect}")
            ?? $default
            ?? self::config()->get('fortify.home'));
    }

    /**
     * Register a request-aware redirect resolver.
     *
     * Boot-only. The callback persists in static state for the worker lifetime and affects every subsequent response for the named Fortify redirect.
     *
     * @param null|(callable(Request): (null|string)) $callback
     */
    public static function redirectUsing(string $redirect, ?callable $callback): void
    {
        if ($callback === null) {
            unset(self::$redirectUsingCallbacks[$redirect]);

            return;
        }

        self::$redirectUsingCallbacks[$redirect] = Closure::fromCallable($callback);
    }

    /**
     * Register the views for Fortify using conventional names under the given namespace.
     *
     * Boot-only. The response bindings persist in the container for the worker lifetime and affect every subsequent request.
     */
    public static function viewNamespace(string $namespace): void
    {
        static::viewPrefix($namespace . '::');
    }

    /**
     * Register the views for Fortify using conventional names under the given prefix.
     *
     * Boot-only. The response bindings persist in the container for the worker lifetime and affect every subsequent request.
     */
    public static function viewPrefix(string $prefix): void
    {
        static::loginView($prefix . 'login');
        static::twoFactorChallengeView($prefix . 'two-factor-challenge');
        static::registerView($prefix . 'register');
        static::requestPasswordResetLinkView($prefix . 'forgot-password');
        static::resetPasswordView($prefix . 'reset-password');
        static::verifyEmailView($prefix . 'verify-email');
        static::confirmPasswordView($prefix . 'confirm-password');
    }

    /**
     * Specify which view should be used as the login view.
     *
     * Boot-only. The response binding persists in the container for the worker lifetime and affects every subsequent request.
     */
    public static function loginView(callable|string $view): void
    {
        self::container()->singleton(LoginViewResponse::class, static fn (): SimpleViewResponse => new SimpleViewResponse($view));
    }

    /**
     * Specify which view should be used as the two factor authentication challenge view.
     *
     * Boot-only. The response binding persists in the container for the worker lifetime and affects every subsequent request.
     */
    public static function twoFactorChallengeView(callable|string $view): void
    {
        self::container()->singleton(TwoFactorChallengeViewResponse::class, static fn (): SimpleViewResponse => new SimpleViewResponse($view));
    }

    /**
     * Specify which view should be used as the new password view.
     *
     * Boot-only. The response binding persists in the container for the worker lifetime and affects every subsequent request.
     */
    public static function resetPasswordView(callable|string $view): void
    {
        self::container()->singleton(ResetPasswordViewResponse::class, static fn (): SimpleViewResponse => new SimpleViewResponse($view));
    }

    /**
     * Specify which view should be used as the registration view.
     *
     * Boot-only. The response binding persists in the container for the worker lifetime and affects every subsequent request.
     */
    public static function registerView(callable|string $view): void
    {
        self::container()->singleton(RegisterViewResponse::class, static fn (): SimpleViewResponse => new SimpleViewResponse($view));
    }

    /**
     * Specify which view should be used as the email verification prompt.
     *
     * Boot-only. The response binding persists in the container for the worker lifetime and affects every subsequent request.
     */
    public static function verifyEmailView(callable|string $view): void
    {
        self::container()->singleton(VerifyEmailViewResponse::class, static fn (): SimpleViewResponse => new SimpleViewResponse($view));
    }

    /**
     * Specify which view should be used as the password confirmation prompt.
     *
     * Boot-only. The response binding persists in the container for the worker lifetime and affects every subsequent request.
     */
    public static function confirmPasswordView(callable|string $view): void
    {
        self::container()->singleton(ConfirmPasswordViewResponse::class, static fn (): SimpleViewResponse => new SimpleViewResponse($view));
    }

    /**
     * Specify which view should be used as the request password reset link view.
     *
     * Boot-only. The response binding persists in the container for the worker lifetime and affects every subsequent request.
     */
    public static function requestPasswordResetLinkView(callable|string $view): void
    {
        self::container()->singleton(RequestPasswordResetLinkViewResponse::class, static fn (): SimpleViewResponse => new SimpleViewResponse($view));
    }

    /**
     * Register a callback that is responsible for building the authentication pipeline array.
     *
     * Boot-only. The callback persists in static state for the worker lifetime and affects every subsequent login request.
     */
    public static function loginThrough(callable $callback): void
    {
        static::authenticateThrough($callback);
    }

    /**
     * Register a callback that is responsible for building the authentication pipeline array.
     *
     * Boot-only. The callback persists in static state for the worker lifetime and affects every subsequent login request.
     */
    public static function authenticateThrough(callable $callback): void
    {
        self::$authenticateThroughCallback = Closure::fromCallable($callback);
    }

    /**
     * Get the configured authentication pipeline callback.
     */
    public static function authenticateThroughCallback(): ?Closure
    {
        return self::$authenticateThroughCallback;
    }

    /**
     * Register a callback that is responsible for validating incoming authentication credentials.
     *
     * Boot-only. The callback persists in static state for the worker lifetime and affects every subsequent login request.
     */
    public static function authenticateUsing(callable $callback): void
    {
        self::$authenticateUsingCallback = Closure::fromCallable($callback);
    }

    /**
     * Get the configured credential authentication callback.
     */
    public static function authenticateUsingCallback(): ?Closure
    {
        return self::$authenticateUsingCallback;
    }

    /**
     * Register a class / callback that should be used to redirect users for two factor authentication.
     *
     * Boot-only. The scoped binding configuration persists for the worker lifetime and affects future requests.
     */
    public static function redirectUserForTwoFactorAuthenticationUsing(callable|string $callback): void
    {
        self::container()->scoped(RedirectsIfTwoFactorAuthenticatable::class, self::bindingConcrete($callback));
    }

    /**
     * Register a callback that is responsible for confirming existing user passwords as valid.
     *
     * Boot-only. The callback persists in static state for the worker lifetime and affects every subsequent password confirmation request.
     */
    public static function confirmPasswordsUsing(callable $callback): void
    {
        self::$confirmPasswordsUsingCallback = Closure::fromCallable($callback);
    }

    /**
     * Get the configured password confirmation callback.
     */
    public static function confirmPasswordsUsingCallback(): ?Closure
    {
        return self::$confirmPasswordsUsingCallback;
    }

    /**
     * Register a class / callback that should be used to create new users.
     *
     * Boot-only. The binding persists in the container for the worker lifetime and affects every subsequent registration request.
     */
    public static function createUsersUsing(callable|string $callback): void
    {
        self::container()->singleton(CreatesNewUsers::class, self::bindingConcrete($callback));
    }

    /**
     * Register a class / callback that should be used to update user profile information.
     *
     * Boot-only. The binding persists in the container for the worker lifetime and affects every subsequent profile update request.
     */
    public static function updateUserProfileInformationUsing(callable|string $callback): void
    {
        self::container()->singleton(UpdatesUserProfileInformation::class, self::bindingConcrete($callback));
    }

    /**
     * Register a class / callback that should be used to update user passwords.
     *
     * Boot-only. The binding persists in the container for the worker lifetime and affects every subsequent password update request.
     */
    public static function updateUserPasswordsUsing(callable|string $callback): void
    {
        self::container()->singleton(UpdatesUserPasswords::class, self::bindingConcrete($callback));
    }

    /**
     * Register a class / callback that should be used to reset user passwords.
     *
     * Boot-only. The binding persists in the container for the worker lifetime and affects every subsequent password reset request.
     */
    public static function resetUserPasswordsUsing(callable|string $callback): void
    {
        self::container()->singleton(ResetsUserPasswords::class, self::bindingConcrete($callback));
    }

    /**
     * Determine if Fortify is confirming two factor authentication configurations.
     */
    public static function confirmsTwoFactorAuthentication(): bool
    {
        return Features::optionEnabled(Features::twoFactorAuthentication(), 'confirm');
    }

    /**
     * Set the encrypter instance that will be used to encrypt attributes.
     *
     * Boot-only. The encrypter persists in static state for the worker lifetime and affects every subsequent encrypted Fortify attribute.
     */
    public static function encryptUsing(?EncrypterContract $encrypter): static
    {
        self::$encrypter = $encrypter;

        return new static;
    }

    /**
     * Get the current encrypter being used by Fortify models.
     */
    public static function currentEncrypter(): EncrypterContract
    {
        return self::$encrypter ?? Model::currentEncrypter();
    }

    /**
     * Get the current authentication guard name.
     */
    public static function guardName(): string
    {
        return self::container()
            ->make(AuthFactory::class)
            ->getDefaultDriver();
    }

    /**
     * Get the current stateful authentication guard.
     */
    public static function guard(): StatefulGuard
    {
        $guard = self::container()
            ->make(AuthFactory::class)
            ->guard(null);

        if (! $guard instanceof StatefulGuard) {
            throw new RuntimeException('Fortify requires a stateful authentication guard.');
        }

        return $guard;
    }

    /**
     * Determine if Fortify routes should be registered.
     */
    public static function shouldRegisterRoutes(): bool
    {
        return self::$registersRoutes;
    }

    /**
     * Configure Fortify to not register its routes.
     *
     * Boot-only. The route registration flag persists in static state for the worker lifetime and affects route bootstrapping.
     */
    public static function ignoreRoutes(): static
    {
        self::$registersRoutes = false;

        return new static;
    }

    /**
     * Get the config repository.
     */
    private static function config(): Config
    {
        return self::container()->make(Config::class);
    }

    /**
     * Get the container instance.
     */
    private static function container(): Container
    {
        return Container::getInstance();
    }

    /**
     * Normalize a public binding callback into a container concrete.
     */
    private static function bindingConcrete(callable|string $callback): Closure|string
    {
        return is_string($callback) ? $callback : Closure::fromCallable($callback);
    }

    /**
     * Flush all static state.
     */
    public static function flushState(): void
    {
        self::$authenticateThroughCallback = null;
        self::$authenticateUsingCallback = null;
        self::$confirmPasswordsUsingCallback = null;
        self::$registersRoutes = self::DEFAULT_REGISTERS_ROUTES;
        self::$encrypter = null;
        self::$redirectUsingCallbacks = [];
    }
}
