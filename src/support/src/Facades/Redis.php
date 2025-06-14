<?php

declare(strict_types=1);

namespace Hypervel\Support\Facades;

use Hypervel\Redis\Redis as RedisClient;

/**
 * @method static \Hyperf\Redis\RedisProxy connection(string $name = 'default')
 * @method static void subscribe(array|string $channels, \Closure $callback)
 * @method static void psubscribe(array|string $channels, \Closure $callback)
 * @method static void scan(mixed $cursor, mixed ...$arguments)
 * @method static void hScan(mixed $key, mixed $cursor, mixed ...$arguments)
 * @method static void zScan(mixed $key, mixed $cursor, mixed ...$arguments)
 * @method static void sScan(mixed $key, mixed $cursor, mixed ...$arguments)
 * @method static array|\Redis pipeline(callable|null $callback = null)
 * @method static array|\Redis|\RedisCluster transaction(callable|null $callback = null)
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
