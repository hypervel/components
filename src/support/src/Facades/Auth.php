<?php

declare(strict_types=1);

namespace Hypervel\Support\Facades;

use Hypervel\Contracts\Auth\StatefulGuard;

/**
 * @method static \Hypervel\Contracts\Auth\Guard|\Hypervel\Contracts\Auth\StatefulGuard guard(\UnitEnum|string|null $name = null)
 * @method static \Hypervel\Auth\SessionGuard createSessionDriver(string $name, array $config)
 * @method static \Hypervel\Auth\TokenGuard createTokenDriver(string $name, array $config)
 * @method static string getDefaultDriver()
 * @method static void shouldUse(\UnitEnum|string|null $name)
 * @method static void setDefaultDriver(\UnitEnum|string $name)
 * @method static \Hypervel\Auth\AuthManager viaRequest(string $driver, callable $callback)
 * @method static \Closure userResolver()
 * @method static \Hypervel\Auth\AuthManager resolveUsersUsing(\Closure $userResolver)
 * @method static void clearUserCache(mixed $identifier, string|null $guard = null)
 * @method static \Hypervel\Auth\AuthManager redirectGuestsTo(callable|string|null $redirect)
 * @method static \Hypervel\Auth\AuthManager redirectUsersTo(callable|string $redirect)
 * @method static \Hypervel\Auth\AuthManager redirectTo(callable|string|null $guests = null, callable|string|null $users = null)
 * @method static \Hypervel\Auth\AuthManager extend(string $driver, \Closure $callback)
 * @method static \Hypervel\Auth\AuthManager provider(string $name, \Closure $callback)
 * @method static bool hasResolvedGuards()
 * @method static \Hypervel\Auth\AuthManager forgetGuards()
 * @method static array getGuards()
 * @method static array<int, string> getAuthContextKeys()
 * @method static \Hypervel\Auth\AuthManager setApplication(\Hypervel\Contracts\Container\Container $app)
 * @method static \Hypervel\Contracts\Auth\UserProvider|null createUserProvider(string|null $provider = null)
 * @method static string|null getDefaultUserProvider()
 *
 * @see \Hypervel\Auth\AuthManager
 * @see \Hypervel\Contracts\Auth\Guard
 * @see \Hypervel\Contracts\Auth\StatefulGuard
 *
 * @mixin \Hypervel\Contracts\Auth\StatefulGuard
 */
class Auth extends Facade
{
    /**
     * Get methods that should be excluded from the generated facade docblock.
     *
     * The guard surface comes from the mixin because @method tags cannot carry
     * the contracts' @phpstan-impure metadata.
     *
     * The documenter excludes by name, so review this hook if AuthManager gains
     * a method with the same name as a guard method.
     *
     * @return array<int, string>
     */
    protected static function ignoredFacadeDocumenterMethods(): array
    {
        return get_class_methods(StatefulGuard::class);
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
