<?php

declare(strict_types=1);

namespace Hypervel\Support\Facades;

use Hypervel\Session\Contracts\Session as SessionContract;

/**
 * @method static \Hypervel\Session\Contracts\Session store(string|null $name = null)
 * @method static bool shouldBlock()
 * @method static string|null blockDriver()
 * @method static int defaultRouteBlockLockSeconds()
 * @method static int defaultRouteBlockWaitSeconds()
 * @method static array getSessionConfig()
 * @method static string getDefaultDriver()
 * @method static void setDefaultDriver(string $name)
 * @method static mixed driver(string|null $driver = null)
 * @method static \Hypervel\Session\SessionManager extend(string $driver, \Closure $callback)
 * @method static array getDrivers()
 * @method static \Psr\Container\ContainerInterface getContainer()
 * @method static \Hypervel\Session\SessionManager setContainer(\Psr\Container\ContainerInterface $container)
 * @method static \Hypervel\Session\SessionManager forgetDrivers()
 * @method static bool start()
 * @method static void save()
 * @method static void ageFlashData()
 * @method static array all()
 * @method static array only(array $keys)
 * @method static array except(array $keys)
 * @method static bool exists(array|string $key)
 * @method static bool missing(array|string $key)
 * @method static bool has(array|string $key)
 * @method static bool hasAny(array|string $key)
 * @method static mixed get(string $key, mixed $default = null)
 * @method static mixed pull(string $key, mixed $default = null)
 * @method static bool hasOldInput(string|null $key = null)
 * @method static mixed getOldInput(string|null $key = null, mixed $default = null)
 * @method static void replace(array $attributes)
 * @method static void put(array|string $key, mixed $value = null)
 * @method static mixed remember(string $key, \Closure $callback)
 * @method static void push(string $key, mixed $value)
 * @method static mixed increment(string $key, int $amount = 1)
 * @method static int decrement(string $key, int $amount = 1)
 * @method static void flash(string $key, mixed $value = true)
 * @method static void now(string $key, mixed $value)
 * @method static void reflash()
 * @method static void keep(array|mixed $keys = null)
 * @method static void flashInput(array $value)
 * @method static mixed remove(string $key)
 * @method static void forget(array|string $keys)
 * @method static void flush()
 * @method static bool invalidate()
 * @method static bool regenerate(bool $destroy = false)
 * @method static bool migrate(bool $destroy = false)
 * @method static bool isStarted()
 * @method static string getName()
 * @method static void setName(string $name)
 * @method static string|null id()
 * @method static string|null getId()
 * @method static void setId(string|null $id)
 * @method static bool isValidId(string|null $id)
 * @method static void setExists(bool $value)
 * @method static string|null token()
 * @method static void regenerateToken()
 * @method static bool hasPreviousUri()
 * @method static string|null previousUrl()
 * @method static void setPreviousUrl(string $url)
 * @method static void passwordConfirmed()
 * @method static \SessionHandlerInterface getHandler()
 * @method static \SessionHandlerInterface setHandler(\SessionHandlerInterface $handler)
 * @method static void macro(string $name, callable|object $macro)
 * @method static void mixin(object $mixin, bool $replace = true)
 * @method static bool hasMacro(string $name)
 *
 * @see \Hypervel\Session\SessionManager
 */
class Session extends Facade
{
    protected static function getFacadeAccessor()
    {
        return SessionContract::class;
    }
}
