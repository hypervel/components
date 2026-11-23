<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis;

use Hypervel\Cache\Redis\AnyTaggedCache;
use Hypervel\Cache\Redis\AnyTagSet;
use RedisException;

/**
 * Tests that RedisException propagates correctly from cache operations.
 *
 * These tests verify that exceptions aren't accidentally swallowed by
 * try/catch blocks in the implementation. Redis errors should bubble up
 * to the caller so they can handle them appropriately.
 */
class ExceptionPropagationTest extends RedisCacheTestCase
{
    // =========================================================================
    // BASIC STORE OPERATIONS
    // =========================================================================

    public function testPutThrowsOnRedisError(): void
    {
        $connection = $this->mockConnection();
        $connection->client()->allows('getLastError')->returns(null);
        $connection->client()->expects('setex')
            ->throws(new RedisException('Connection refused'));

        $store = $this->createStore($connection);

        $this->expectException(RedisException::class);
        $this->expectExceptionMessageIs('Connection refused');

        $store->put('key', 'value', 60);
    }

    public function testGetThrowsOnRedisError(): void
    {
        $connection = $this->mockConnection();
        $connection->client()->allows('getLastError')->returns(null);
        $connection->client()->expects('get')
            ->throws(new RedisException('Connection timed out'));

        $store = $this->createStore($connection);

        $this->expectException(RedisException::class);
        $this->expectExceptionMessageIs('Connection timed out');

        $store->get('key');
    }

    public function testForgetThrowsOnRedisError(): void
    {
        $connection = $this->mockConnection();
        $connection->client()->allows('getLastError')->returns('READONLY You can\'t write against a read only replica');
        $connection->client()->expects('del')
            ->throws(new RedisException('READONLY You can\'t write against a read only replica'));

        $store = $this->createStore($connection);

        $this->expectException(RedisException::class);
        $this->expectExceptionMessageIsOrContains('READONLY');

        $store->forget('key');
    }

    public function testIncrementThrowsOnRedisError(): void
    {
        $connection = $this->mockConnection();
        $connection->client()->allows('getLastError')->returns('OOM command not allowed when used memory > maxmemory');
        $connection->client()->expects('incrby')
            ->throws(new RedisException('OOM command not allowed when used memory > maxmemory'));

        $store = $this->createStore($connection);

        $this->expectException(RedisException::class);
        $this->expectExceptionMessageIsOrContains('OOM');

        $store->increment('counter', 1);
    }

    public function testDecrementThrowsOnRedisError(): void
    {
        $connection = $this->mockConnection();
        $connection->client()->allows('getLastError')->returns('NOAUTH Authentication required');
        $connection->client()->expects('decrby')
            ->throws(new RedisException('NOAUTH Authentication required'));

        $store = $this->createStore($connection);

        $this->expectException(RedisException::class);
        $this->expectExceptionMessageIsOrContains('NOAUTH');

        $store->decrement('counter', 1);
    }

    public function testForeverThrowsOnRedisError(): void
    {
        $connection = $this->mockConnection();
        $connection->client()->allows('getLastError')->returns('ERR invalid DB index');
        $connection->client()->expects('set')
            ->throws(new RedisException('ERR invalid DB index'));

        $store = $this->createStore($connection);

        $this->expectException(RedisException::class);
        $this->expectExceptionMessageIs('ERR invalid DB index');

        $store->forever('key', 'value');
    }

    // =========================================================================
    // TAGGED OPERATIONS (ANY MODE)
    // =========================================================================

    public function testTaggedPutThrowsOnRedisError(): void
    {
        $connection = $this->mockConnection();

        // Tagged put uses evalWithShaCache for Lua script
        $connection->expects('evalWithShaCache')
            ->throws(new RedisException('Connection lost'));

        $store = $this->createStore($connection, tagMode: 'any');
        $taggedCache = new AnyTaggedCache($store, new AnyTagSet($store, ['test-tag']));

        $this->expectException(RedisException::class);
        $this->expectExceptionMessageIs('Connection lost');

        $taggedCache->put('key', 'value', 60);
    }

    public function testTaggedIncrementThrowsOnRedisError(): void
    {
        $connection = $this->mockConnection();

        // Tagged increment uses evalWithShaCache for Lua script
        $connection->expects('evalWithShaCache')
            ->throws(new RedisException('Connection reset by peer'));

        $store = $this->createStore($connection, tagMode: 'any');
        $taggedCache = new AnyTaggedCache($store, new AnyTagSet($store, ['test-tag']));

        $this->expectException(RedisException::class);
        $this->expectExceptionMessageIs('Connection reset by peer');

        $taggedCache->increment('counter', 1);
    }

    public function testTaggedFlushThrowsOnRedisError(): void
    {
        $connection = $this->mockConnection();

        // Flush calls hlen to check hash size
        $connection->client()->allows('getLastError')->returns('ERR unknown command');
        $connection->client()->expects('hlen')
            ->throws(new RedisException('ERR unknown command'));

        $store = $this->createStore($connection, tagMode: 'any');
        $taggedCache = new AnyTaggedCache($store, new AnyTagSet($store, ['test-tag']));

        $this->expectException(RedisException::class);
        $this->expectExceptionMessageIs('ERR unknown command');

        $taggedCache->flush();
    }

    // =========================================================================
    // BULK OPERATIONS
    // =========================================================================

    public function testPutManyThrowsOnRedisError(): void
    {
        $connection = $this->mockConnection();

        // PutMany uses evalWithShaCache for Lua script
        $connection->expects('evalWithShaCache')
            ->throws(new RedisException('CLUSTERDOWN The cluster is down'));

        $store = $this->createStore($connection);

        $this->expectException(RedisException::class);
        $this->expectExceptionMessageIsOrContains('CLUSTERDOWN');

        $store->putMany(['key1' => 'value1', 'key2' => 'value2'], 60);
    }

    public function testManyThrowsOnRedisError(): void
    {
        $connection = $this->mockConnection();
        $connection->client()->allows('getLastError')->returns('LOADING Redis is loading the dataset in memory');
        $connection->client()->expects('mget')
            ->throws(new RedisException('LOADING Redis is loading the dataset in memory'));

        $store = $this->createStore($connection);

        $this->expectException(RedisException::class);
        $this->expectExceptionMessageIsOrContains('LOADING');

        $store->many(['key1', 'key2']);
    }

    // =========================================================================
    // STORE-LEVEL FLUSH
    // =========================================================================

    public function testFlushThrowsOnRedisError(): void
    {
        $connection = $this->mockConnection();
        $connection->client()->allows('getLastError')->returns('MISCONF Redis is configured to save RDB snapshots');
        $connection->client()->expects('flushdb')
            ->throws(new RedisException('MISCONF Redis is configured to save RDB snapshots'));

        $store = $this->createStore($connection);

        $this->expectException(RedisException::class);
        $this->expectExceptionMessageIsOrContains('MISCONF');

        $store->flush();
    }
}
