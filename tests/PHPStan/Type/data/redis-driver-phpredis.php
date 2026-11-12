<?php

namespace RedisDriverPhpRedis;

use Hypervel\Redis\PhpRedisConnection;
use Hypervel\Support\Facades\Redis;

use function PHPStan\Testing\assertType;

// Hypervel: the types are the source's, where a pooled connection may hold a Redis Cluster client.
function test(): void
{
    // #2251 / #2622 — Laravel PhpRedisConnection pipeline/transaction
    // Hypervel: with a callback, exec() returns false when the commands fail; without one, the
    // client in pipeline or MULTI mode is returned, and Redis Cluster has no pipeline().
    assertType('array<int, mixed>|false', Redis::pipeline(function ($pipe): void {
        $pipe->set('foo', 'bar');
    }));
    assertType('array<int, mixed>|false', Redis::transaction(fn ($transaction) => $transaction->incr('foo')));
    assertType('Redis', Redis::pipeline());
    assertType('Redis|RedisCluster', Redis::transaction());

    // #907 — Laravel set arity (not ext-redis's 2–3 arg set)
    // Hypervel: SET with the GET option returns the previous decoded value.
    assertType('mixed', Redis::set('key', 'value', 'EX', 60));
    assertType('mixed', Redis::set('key', 'value', 'EX', 60, 'NX'));

    // #907 — phpredis return types (via PhpRedisConnection @mixin \Redis)
    assertType('bool|Redis|RedisCluster', Redis::hexists('h', 'field'));
    assertType('float|Redis|RedisCluster|false', Redis::zscore('z', 'member'));

    // #2453 — Laravel eval order: script, numKeys, ...args
    assertType('mixed', Redis::eval('return 1', 0));
    assertType('mixed', Redis::eval('return redis.call("get", KEYS[1])', 1, 'mykey'));

    // #14 smoke
    assertType('int|Redis|RedisCluster|false', Redis::publish('channel', 'message'));
    assertType('array|Redis|RedisCluster|false', Redis::hmget('h', ['field1', 'field2']));
    assertType('bool|Redis|RedisCluster', Redis::expire('foo', 3));

    $connection = Redis::connection();
    assertType('Hypervel\Redis\RedisProxy', $connection);
    assertType('array<int, mixed>|false', $connection->pipeline(function ($pipe): void {
        $pipe->get('foo');
    }));
}

/**
 * Hypervel: connections lowercase a command's name before running it, so every casing
 * calls the documented command. A macro is matched by its exact name first.
 */
function commandCasing(PhpRedisConnection $held): void
{
    assertType('bool|Redis|RedisCluster', Redis::hExists('h', 'field'));
    assertType('bool|Redis|RedisCluster', Redis::HEXISTS('h', 'field'));
    assertType('float|Redis|RedisCluster|false', Redis::connection()->ZSCORE('z', 'member'));
    assertType('bool|int|Redis|RedisCluster', Redis::setNx('key', 'value'));
    assertType('bool|Redis|RedisCluster', $held->hexists('h', 'field'));
    assertType('bool|Redis', $held->pipeline());

    // The bootstrap registers zrank as a macro, which replaces the zRank command for that exact name.
    assertType('string', $held->zrank('z', 'member'));
    assertType('int|Redis|RedisCluster|false', $held->ZRANK('z', 'member'));

    // Macro names are not case-folded.
    assertType('int', $held->customCommand());
    assertType('*ERROR*', $held->customcommand());
}

/**
 * Hypervel: connections that only analyzed files declare, which the booted application never loaded.
 */
class OwnRegistryConnection extends PhpRedisConnection
{
    protected static array $macros = [];
}

class InheritedRegistryConnection extends PhpRedisConnection
{
}

function unloadedConnections(OwnRegistryConnection $own, InheritedRegistryConnection $inherited): void
{
    // The unloaded class's own registry is unavailable, so its calls are commands, not the parent's macros.
    assertType('float|Redis|RedisCluster|false', $own->zscore('z', 'member'));
    assertType('int|Redis|RedisCluster|false', $own->zrank('z', 'member'));

    // The loaded parent's registry still supplies its macro.
    assertType('string', $inherited->zrank('z', 'member'));
}
