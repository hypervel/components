<?php

declare(strict_types=1);

namespace Hypervel\Support\Facades;

use Mockery;

/**
 * @method static \Hypervel\Contracts\Cache\Repository store(string|null $name = null)
 * @method static \Hypervel\Contracts\Cache\Repository driver(string|null $driver = null)
 * @method static \Hypervel\Contracts\Cache\Repository memo(string|null $driver = null)
 * @method static \Hypervel\Contracts\Cache\Repository resolve(string $name)
 * @method static \Hypervel\Contracts\Cache\Repository build(array $config)
 * @method static \Hypervel\Cache\Repository repository(\Hypervel\Contracts\Cache\Store $store, array $config = [])
 * @method static void refreshEventDispatcher()
 * @method static string getDefaultDriver()
 * @method static void setDefaultDriver(string $name)
 * @method static \Hypervel\Cache\CacheManager forgetDriver(array|string|null $name = null)
 * @method static void purge(string|null $name = null)
 * @method static \Hypervel\Cache\CacheManager extend(string $driver, \Closure $callback)
 * @method static \Hypervel\Cache\CacheManager setApplication(\Hypervel\Contracts\Container\Container $app)
 * @method static mixed pull(\UnitEnum|string $key, \Closure|mixed $default = null)
 * @method static bool put(\UnitEnum|array|string $key, mixed $value, \DateInterval|\DateTimeInterface|int|null $ttl = null)
 * @method static bool add(\UnitEnum|string $key, mixed $value, \DateInterval|\DateTimeInterface|int|null $ttl = null)
 * @method static int|bool increment(\UnitEnum|string $key, int $value = 1)
 * @method static int|bool decrement(\UnitEnum|string $key, int $value = 1)
 * @method static bool forever(\UnitEnum|string $key, mixed $value)
 * @method static mixed remember(\UnitEnum|string $key, \DateInterval|\DateTimeInterface|int|null $ttl, \Closure $callback)
 * @method static mixed sear(\UnitEnum|string $key, \Closure $callback)
 * @method static mixed rememberForever(\UnitEnum|string $key, \Closure $callback)
 * @method static mixed rememberNullable(\UnitEnum|string $key, \DateInterval|\DateTimeInterface|int|null $ttl, \Closure $callback)
 * @method static mixed searNullable(\UnitEnum|string $key, \Closure $callback)
 * @method static mixed rememberForeverNullable(\UnitEnum|string $key, \Closure $callback)
 * @method static bool touch(\UnitEnum|string $key, \DateInterval|\DateTimeInterface|int|null $ttl = null)
 * @method static bool forget(\UnitEnum|string $key)
 * @method static \Hypervel\Contracts\Cache\Store getStore()
 * @method static mixed get(string $key, mixed $default = null)
 * @method static bool set(string $key, mixed $value, null|int|\DateInterval $ttl = null)
 * @method static bool delete(string $key)
 * @method static bool clear()
 * @method static iterable<string, mixed> getMultiple(iterable<string> $keys, mixed $default = null)
 * @method static bool setMultiple(iterable $values, null|int|\DateInterval $ttl = null)
 * @method static bool deleteMultiple(iterable<string> $keys)
 * @method static bool has(string $key)
 * @method static \Hypervel\Contracts\Cache\Lock lock(string $name, int $seconds = 0, string|null $owner = null)
 * @method static \Hypervel\Contracts\Cache\Lock restoreLock(string $name, string $owner)
 * @method static \Hypervel\Cache\TaggedCache tags(mixed $names)
 * @method static \Hypervel\Cache\TagMode getTagMode()
 * @method static array many(array $keys)
 * @method static bool putMany(array $values, int $seconds)
 * @method static bool flush()
 * @method static string getPrefix()
 * @method static bool missing(\UnitEnum|string $key)
 * @method static string string(\UnitEnum|string $key, null|\Closure|string $default = null)
 * @method static int integer(\UnitEnum|string $key, null|\Closure|int $default = null)
 * @method static float float(\UnitEnum|string $key, null|\Closure|float $default = null)
 * @method static bool boolean(\UnitEnum|string $key, null|bool|\Closure $default = null)
 * @method static array<array-key, mixed> array(\UnitEnum|string $key, null|array<array-key, mixed>|\Closure $default = null)
 * @method static mixed flexible(\UnitEnum|string $key, array $ttl, callable $callback, null|array $lock = null, bool $alwaysDefer = false)
 * @method static mixed flexibleNullable(\UnitEnum|string $key, array $ttl, callable $callback, null|array $lock = null, bool $alwaysDefer = false)
 * @method static mixed withoutOverlapping(\UnitEnum|string $key, callable $callback, int $lockFor = 0, int $waitFor = 10, string|null $owner = null)
 * @method static \Hypervel\Cache\Limiters\ConcurrencyLimiterBuilder funnel(\UnitEnum|string $name)
 * @method static bool flushLocks()
 * @method static bool supportsTags()
 * @method static bool supportsFlushingLocks()
 * @method static int|null getDefaultCacheTime()
 * @method static \Hypervel\Cache\Repository setDefaultCacheTime(int|null $seconds)
 * @method static \Hypervel\Cache\Repository setStore(\Hypervel\Contracts\Cache\Store $store)
 * @method static \Hypervel\Contracts\Events\Dispatcher|null getEventDispatcher()
 * @method static void setEventDispatcher(\Hypervel\Contracts\Events\Dispatcher $events)
 * @method static string|null getName()
 * @method static void flushState()
 * @method static void macro(string $name, callable|object $macro)
 * @method static void mixin(object $mixin, bool $replace = true)
 * @method static bool hasMacro(string $name)
 * @method static void flushMacros()
 * @method static mixed macroCall(string $method, array $parameters)
 *
 * @see \Hypervel\Cache\CacheManager
 * @see \Hypervel\Cache\Repository
 */
class Cache extends Facade
{
    /**
     * Initiate a spy on the cache facade.
     *
     * Uses a partial spy on the real instance so that methods like `memo()`
     * execute their real implementation while still recording calls.
     */
    public static function spy()
    {
        if (! static::isMock()) {
            $class = static::getMockableClass();
            $instance = static::getFacadeRoot();

            if ($class && $instance) {
                return tap(Mockery::spy($instance)->makePartial(), function ($spy) {
                    static::swap($spy);
                });
            }

            return tap($class ? Mockery::spy($class) : Mockery::spy(), function ($spy) {
                static::swap($spy);
            });
        }
    }

    protected static function getFacadeAccessor(): string
    {
        return 'cache';
    }
}
