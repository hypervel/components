<?php

declare(strict_types=1);

namespace Hypervel\Support\Facades;

use Hypervel\Contracts\Cache\Factory;

/**
 * @method static \Hypervel\Contracts\Cache\Repository store(string|null $name = null)
 * @method static \Hypervel\Contracts\Cache\Repository driver(string|null $driver = null)
 * @method static \Hypervel\Cache\Repository repository(\Hypervel\Contracts\Cache\Store $store, array $config = [])
 * @method static void refreshEventDispatcher()
 * @method static string getDefaultDriver()
 * @method static void setDefaultDriver(string $name)
 * @method static \Hypervel\Cache\CacheManager forgetDriver(array|string|null $name = null)
 * @method static void purge(string|null $name = null)
 * @method static \Hypervel\Cache\CacheManager extend(string $driver, \Closure $callback)
 * @method static \Hypervel\Cache\CacheManager setApplication(\Psr\Container\ContainerInterface $app)
 * @method static mixed pull(string $key, \Closure|mixed $default = null)
 * @method static bool put(array|string $key, mixed $value, \DateInterval|\DateTimeInterface|int|null $ttl = null)
 * @method static bool add(string $key, mixed $value, \DateInterval|\DateTimeInterface|int|null $ttl = null)
 * @method static int|bool increment(string $key, int $value = 1)
 * @method static int|bool decrement(string $key, int $value = 1)
 * @method static bool forever(string $key, mixed $value)
 * @method static mixed remember(string $key, \DateInterval|\DateTimeInterface|int|null $ttl, \Closure $callback)
 * @method static mixed sear(string $key, \Closure $callback)
 * @method static mixed rememberForever(string $key, \Closure $callback)
 * @method static bool forget(string $key)
 * @method static \Hypervel\Contracts\Cache\Store getStore()
 * @method static mixed get(string $key, mixed $default = null)
 * @method static bool set(string $key, mixed $value, null|int|\DateInterval $ttl = null)
 * @method static bool delete(string $key)
 * @method static bool clear()
 * @method static iterable getMultiple(iterable $keys, mixed $default = null)
 * @method static bool setMultiple(iterable $values, null|int|\DateInterval $ttl = null)
 * @method static bool deleteMultiple(iterable $keys)
 * @method static bool has(string $key)
 * @method static \Hypervel\Contracts\Cache\Lock lock(string $name, int $seconds = 0, string|null $owner = null)
 * @method static \Hypervel\Contracts\Cache\Lock restoreLock(string $name, string $owner)
 * @method static \Hypervel\Cache\TaggedCache tags(mixed $names)
 * @method static array many(array $keys)
 * @method static bool putMany(array $values, int $seconds)
 * @method static bool flush()
 * @method static string getPrefix()
 * @method static bool missing(\UnitEnum|string $key)
 * @method static bool supportsTags()
 * @method static int|null getDefaultCacheTime()
 * @method static \Hypervel\Cache\Repository setDefaultCacheTime(int|null $seconds)
 * @method static \Psr\EventDispatcher\EventDispatcherInterface|null getEventDispatcher()
 * @method static void setEventDispatcher(\Psr\EventDispatcher\EventDispatcherInterface $events)
 * @method static string|null getName()
 * @method static mixed macroCall(string $method, array $parameters)
 * @method static void macro(string $name, callable|object $macro)
 * @method static void mixin(object $mixin, bool $replace = true)
 * @method static bool hasMacro(string $name)
 *
 * @see \Hypervel\Cache\CacheManager
 * @see \Hypervel\Cache\Repository
 */
class Cache extends Facade
{
    protected static function getFacadeAccessor()
    {
        return Factory::class;
    }
}
