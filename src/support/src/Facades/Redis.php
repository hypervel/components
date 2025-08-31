<?php

declare(strict_types=1);

namespace Hypervel\Support\Facades;

use Hypervel\Redis\Redis as RedisClient;

/**
 * @method static \Hypervel\Redis\RedisProxy connection(string $name = 'default')
 * @method static void release()
 * @method static \Hypervel\Redis\RedisConnection shouldTransform(bool $shouldTransform = true)
 * @method static bool getShouldTransform()
 * @method static mixed scan(mixed $cursor, array ...$arguments)
 * @method static mixed zscan(string $key, mixed $cursor, array ...$arguments)
 * @method static mixed hscan(string $key, mixed $cursor, array ...$arguments)
 * @method static mixed sscan(string $key, mixed $cursor, array ...$arguments)
 * @method static void getActiveConnection()
 * @method static \Psr\EventDispatcher\EventDispatcherInterface|null getEventDispatcher()
 * @method static bool reconnect()
 * @method static bool close()
 * @method static void setDatabase(int|null $database)
 * @method static void getConnection()
 * @method static bool check()
 * @method static float getLastUseTime()
 * @method static float getLastReleaseTime()
 * @method static array|\Redis pipeline(callable|null $callback = null)
 * @method static array|\Redis|\RedisCluster transaction(callable|null $callback = null)
 * @method static mixed get(string $key)
 * @method static bool set(string $key, mixed $value, mixed $expireResolution = null, mixed $expireTTL = null, mixed $flag = null)
 * @method static array mget(array $keys)
 * @method static int setnx(string $key, string $value)
 * @method static array hmget(string $key, mixed ...$fields)
 * @method static bool hmset(string $key, mixed ...$dictionary)
 * @method static int hsetnx(string $hash, string $key, string $value)
 * @method static false|int lrem(string $key, int $count, mixed $value)
 * @method static null|array blpop(mixed ...$arguments)
 * @method static null|array brpop(mixed ...$arguments)
 * @method static mixed spop(string $key, int $count = 1)
 * @method static int zadd(string $key, mixed ...$dictionary)
 * @method static array zrangebyscore(string $key, mixed $min, mixed $max, array $options = [])
 * @method static array zrevrangebyscore(string $key, mixed $min, mixed $max, array $options = [])
 * @method static int zinterstore(string $output, array $keys, array $options = [])
 * @method static int zunionstore(string $output, array $keys, array $options = [])
 * @method static mixed eval(string $script, int $numberOfKeys, mixed ...$arguments)
 * @method static mixed evalsha(string $script, int $numkeys, mixed ...$arguments)
 * @method static mixed flushdb(mixed ...$arguments)
 * @method static mixed executeRaw(array $parameters)
 *
 * @see \Hypervel\Redis\Redis
 */
class Redis extends Facade
{
    protected static function getFacadeAccessor()
    {
        return RedisClient::class;
    }
}
