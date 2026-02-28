<?php

declare(strict_types=1);

namespace Hypervel\Support\Facades;

use Hypervel\Auth\AuthManager;
use Hypervel\Contracts\Auth\Guard;

/**
 * @method static \Hypervel\Contracts\Auth\Guard|\Hypervel\Contracts\Auth\StatefulGuard guard(string|null $name = null)
 * @method static \Hypervel\Auth\Guards\SessionGuard createSessionDriver(string $name, array $config)
 * @method static \Hypervel\Auth\Guards\JwtGuard createJwtDriver(string $name, array $config)
 * @method static \Hypervel\Auth\AuthManager extend(string $driver, \Closure $callback)
 * @method static \Hypervel\Auth\AuthManager provider(string $name, \Closure $callback)
 * @method static string getDefaultDriver()
 * @method static void shouldUse(string|null $name)
 * @method static void setDefaultDriver(string $name)
 * @method static \Hypervel\Auth\AuthManager viaRequest(string $driver, callable $callback)
 * @method static \Closure userResolver()
 * @method static \Hypervel\Auth\AuthManager resolveUsersUsing(\Closure $userResolver)
 * @method static array getGuards()
 * @method static \Hypervel\Auth\AuthManager setApplication(\Hypervel\Contracts\Container\Container $app)
 * @method static \Hypervel\Contracts\Auth\UserProvider|null createUserProvider(string|null $provider = null)
 * @method static string getDefaultUserProvider()
 * @method static bool check()
 * @method static bool guest()
 * @method static \Hypervel\Contracts\Auth\Authenticatable|null user()
 * @method static string|int|null id()
 * @method static bool validate(array $credentials = [])
 * @method static void setUser(\Hypervel\Contracts\Auth\Authenticatable $user)
 * @method static bool attempt(array $credentials = [])
 * @method static bool once(array $credentials = [])
 * @method static void login(\Hypervel\Contracts\Auth\Authenticatable $user)
 * @method static \Hypervel\Contracts\Auth\Authenticatable|bool loginUsingId(mixed $id)
 * @method static \Hypervel\Contracts\Auth\Authenticatable|bool onceUsingId(mixed $id)
 * @method static void logout()
 *
 * @see \Hypervel\Auth\AuthManager
 * @see \Hypervel\Contracts\Auth\Guard
 * @see \Hypervel\Contracts\Auth\StatefulGuard
 */
class Auth extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return AuthManager::class;
    }
}
