<?php

declare(strict_types=1);

namespace Hypervel\Support\Facades;

use Hypervel\Contracts\Auth\StatefulGuard;
use Hypervel\Contracts\Auth\SupportsBasicAuth;

/**
 * @method static void clearUserCache(mixed $identifier, \UnitEnum|string|null $guard = null)
 * @method static \Hypervel\Auth\SessionGuard createSessionDriver(string $name, array $config)
 * @method static \Hypervel\Auth\TokenGuard createTokenDriver(string $name, array $config)
 * @method static \Hypervel\Contracts\Auth\UserProvider|null createUserProvider(string|null $provider = null)
 * @method static \Hypervel\Auth\AuthManager extend(string $driver, \Closure $callback)
 * @method static \Hypervel\Auth\AuthManager forgetGuards()
 * @method static array<int, string> getAuthContextKeys()
 * @method static string getDefaultDriver()
 * @method static string|null getDefaultUserProvider()
 * @method static array getGuards()
 * @method static string|null getUserProviderName(\UnitEnum|string|null $guard = null)
 * @method static \Hypervel\Contracts\Auth\Guard|\Hypervel\Contracts\Auth\StatefulGuard guard(\UnitEnum|string|null $name = null)
 * @method static bool hasResolvedGuards()
 * @method static \Hypervel\Auth\AuthManager provider(string $name, \Closure $callback)
 * @method static \Hypervel\Auth\AuthManager redirectGuestsTo(callable|string|null $redirect)
 * @method static \Hypervel\Auth\AuthManager redirectTo(callable|string|null $guests = null, callable|string|null $users = null)
 * @method static \Hypervel\Auth\AuthManager redirectUsersTo(callable|string $redirect)
 * @method static \Hypervel\Auth\AuthManager resolveUsersUsing(\Closure $userResolver)
 * @method static \Hypervel\Auth\AuthManager setApplication(\Hypervel\Contracts\Container\Container $app)
 * @method static void setDefaultDriver(\UnitEnum|string $name)
 * @method static void shouldUse(\UnitEnum|string|null $name)
 * @method static \Closure userResolver()
 * @method static \Hypervel\Auth\AuthManager viaRequest(string $driver, callable $callback)
 * @method static void attempting(callable $callback)
 * @method static bool attemptWhen(array $credentials = [], callable|array|null $callbacks = null, bool $remember = false)
 * @method static \Hypervel\Contracts\Auth\Authenticatable authenticate()
 * @method static void flushMacros()
 * @method static void flushState()
 * @method static \Hypervel\Auth\SessionGuard forgetUser()
 * @method static \Hypervel\Contracts\Cookie\QueueingFactory getCookieJar()
 * @method static \Hypervel\Contracts\Events\Dispatcher|null getDispatcher()
 * @method static \Hypervel\Contracts\Auth\Authenticatable|null getLastAttempted()
 * @method static string getName()
 * @method static \Hypervel\Contracts\Auth\UserProvider|null getProvider()
 * @method static string getRecallerName()
 * @method static \Symfony\Component\HttpFoundation\Request getRequest()
 * @method static \Hypervel\Contracts\Session\Session getSession()
 * @method static \Hypervel\Support\Timebox getTimebox()
 * @method static \Hypervel\Contracts\Auth\Authenticatable|null getUser()
 * @method static string hashPasswordForCookie(string|null $passwordHash)
 * @method static bool hasMacro(string $name)
 * @method static void logoutCurrentDevice()
 * @method static \Hypervel\Contracts\Auth\Authenticatable|null logoutOtherDevices(string $password)
 * @method static void macro(string $name, callable|object $macro)
 * @method static void mixin(object $mixin, bool $replace = true)
 * @method static void setCookieJar(\Hypervel\Contracts\Cookie\QueueingFactory $cookie)
 * @method static void setDispatcher(\Hypervel\Contracts\Events\Dispatcher $events)
 * @method static void setProvider(\Hypervel\Contracts\Auth\UserProvider $provider)
 * @method static \Hypervel\Auth\SessionGuard setRememberDuration(int $minutes)
 *
 * @see \Hypervel\Auth\AuthManager
 * @see \Hypervel\Auth\SessionGuard
 *
 * @mixin \Hypervel\Contracts\Auth\StatefulGuard
 * @mixin \Hypervel\Contracts\Auth\SupportsBasicAuth
 */
class Auth extends Facade
{
    /**
     * Get methods that should be excluded from the generated facade docblock.
     *
     * The guard contracts come from mixins because @method tags cannot carry
     * the contracts' @phpstan-impure metadata.
     *
     * The documenter excludes by name, so review this hook if AuthManager gains
     * a method with the same name as a guard method.
     *
     * @return array<int, string>
     */
    protected static function ignoredFacadeDocumenterMethods(): array
    {
        return [
            ...get_class_methods(StatefulGuard::class),
            ...get_class_methods(SupportsBasicAuth::class),
        ];
    }

    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return 'auth';
    }

    // REMOVED: Auth::routes() requires laravel/ui, which Hypervel does not integrate.
}
