<?php

declare(strict_types=1);

namespace Hypervel\Support\Facades;

use Hypervel\Auth\AuthManager;
use Hypervel\Auth\Contracts\Guard;

/**
 * @method static \Hypervel\Auth\Contracts\Guard|\Hypervel\Auth\Contracts\StatefulGuard guard(string|null $name = null)
 * @method static \Hypervel\Auth\Guards\SessionGuard createSessionDriver(string $name, array $config)
 * @method static \Hypervel\Auth\Guards\JwtGuard createJwtDriver(string $name, array $config)
 * @method static \Hypervel\Auth\AuthManager extend(string $driver, \Closure $callback)
 * @method static \Hypervel\Auth\AuthManager provider(string $name, \Closure $callback)
 * @method static string getDefaultDriver()
 * @method static void shouldUse(string|null $name)
 * @method static void setDefaultDriver(string $name)
 * @method static \Closure userResolver()
 * @method static \Hypervel\Auth\AuthManager resolveUsersUsing(\Closure $userResolver)
 * @method static array getGuards()
 * @method static \Hypervel\Auth\AuthManager setApplication(\Psr\Container\ContainerInterface $app)
 * @method static \Hypervel\Auth\Contracts\UserProvider|null createUserProvider(string|null $provider = null)
 * @method static string getDefaultUserProvider()
 * @method static bool check()
 * @method static bool guest()
 * @method static \Hypervel\Auth\Contracts\Authenticatable|null user()
 * @method static string|int|null id()
 * @method static bool validate(array $credentials = [])
 * @method static void setUser(\Hypervel\Auth\Contracts\Authenticatable $user)
 * @method static bool attempt(array $credentials = [])
 * @method static bool once(array $credentials = [])
 * @method static void login(\Hypervel\Auth\Contracts\Authenticatable $user)
 * @method static \Hypervel\Auth\Contracts\Authenticatable|bool loginUsingId(mixed $id)
 * @method static \Hypervel\Auth\Contracts\Authenticatable|bool onceUsingId(mixed $id)
 * @method static void logout()
 *
 * @see \Hypervel\Auth\AuthManager
 * @see \Hypervel\Auth\Contracts\Guard
 * @see \Hypervel\Auth\Contracts\StatefulGuard
 */
class Auth extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return AuthManager::class;
    }
}
