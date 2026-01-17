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
 *
 * @internal
 * @coversNothing
 */
class ExceptionPropagationTest extends RedisCacheTestCase
{
    // =========================================================================
    // BASIC STORE OPERATIONS
    // =========================================================================

    public function testPutThrowsOnRedisError(): void
    {
        $connection = $this->mockConnection();
        $connection->shouldReceive('setex')
            ->andThrow(new RedisException('Connection refused'));

        $store = $this->createStore($connection);

        $this->expectException(RedisException::class);
        $this->expectExceptionMessage('Connection refused');

        $store->put('key', 'value', 60);
    }

    public function testGetThrowsOnRedisError(): void
    {
        $connection = $this->mockConnection();
        $connection->shouldReceive('get')
            ->andThrow(new RedisException('Connection timed out'));

        $store = $this->createStore($connection);

        $this->expectException(RedisException::class);
        $this->expectExceptionMessage('Connection timed out');

        $store->get('key');
    }

    public function testForgetThrowsOnRedisError(): void
    {
        $connection = $this->mockConnection();
        $connection->shouldReceive('del')
            ->andThrow(new RedisException('READONLY You can\'t write against a read only replica'));

        $store = $this->createStore($connection);

        $this->expectException(RedisException::class);
        $this->expectExceptionMessage('READONLY');

        $store->forget('key');
    }

    public function testIncrementThrowsOnRedisError(): void
    {
        $connection = $this->mockConnection();
        $connection->shouldReceive('incrby')
            ->andThrow(new RedisException('OOM command not allowed when used memory > maxmemory'));

        $store = $this->createStore($connection);

        $this->expectException(RedisException::class);
        $this->expectExceptionMessage('OOM');

        $store->increment('counter', 1);
    }

    public function testDecrementThrowsOnRedisError(): void
    {
        $connection = $this->mockConnection();
        $connection->shouldReceive('decrby')
            ->andThrow(new RedisException('NOAUTH Authentication required'));

        $store = $this->createStore($connection);

        $this->expectException(RedisException::class);
        $this->expectExceptionMessage('NOAUTH');

        $store->decrement('counter', 1);
    }

    public function testForeverThrowsOnRedisError(): void
    {
        $connection = $this->mockConnection();
        $connection->shouldReceive('set')
            ->andThrow(new RedisException('ERR invalid DB index'));

        $store = $this->createStore($connection);

        $this->expectException(RedisException::class);
        $this->expectExceptionMessage('ERR invalid DB index');

        $store->forever('key', 'value');
    }

    // =========================================================================
    // TAGGED OPERATIONS (ANY MODE)
    // =========================================================================

    public function testTaggedPutThrowsOnRedisError(): void
    {
        $connection = $this->mockConnection();

        // Tagged put uses evalSha for Lua script
        $connection->_mockClient->shouldReceive('evalSha')
            ->andThrow(new RedisException('Connection lost'));

        $store = $this->createStore($connection, tagMode: 'any');
        $taggedCache = new AnyTaggedCache($store, new AnyTagSet($store, ['test-tag']));

        $this->expectException(RedisException::class);
        $this->expectExceptionMessage('Connection lost');

        $taggedCache->put('key', 'value', 60);
    }

    public function testTaggedIncrementThrowsOnRedisError(): void
    {
        $connection = $this->mockConnection();

        // Tagged increment uses evalSha for Lua script
        $connection->_mockClient->shouldReceive('evalSha')
            ->andThrow(new RedisException('Connection reset by peer'));

        $store = $this->createStore($connection, tagMode: 'any');
        $taggedCache = new AnyTaggedCache($store, new AnyTagSet($store, ['test-tag']));

        $this->expectException(RedisException::class);
        $this->expectExceptionMessage('Connection reset by peer');

        $taggedCache->increment('counter', 1);
    }

    public function testTaggedFlushThrowsOnRedisError(): void
    {
        $connection = $this->mockConnection();

        // Flush calls hlen on the raw client to check hash size
        $connection->_mockClient->shouldReceive('hlen')
            ->andThrow(new RedisException('ERR unknown command'));

        $store = $this->createStore($connection, tagMode: 'any');
        $taggedCache = new AnyTaggedCache($store, new AnyTagSet($store, ['test-tag']));

        $this->expectException(RedisException::class);
        $this->expectExceptionMessage('ERR unknown command');

        $taggedCache->flush();
    }

    // =========================================================================
    // BULK OPERATIONS
    // =========================================================================

    public function testPutManyThrowsOnRedisError(): void
    {
        $connection = $this->mockConnection();

        // PutMany uses evalSha for Lua script
        $connection->_mockClient->shouldReceive('evalSha')
            ->andThrow(new RedisException('CLUSTERDOWN The cluster is down'));

        $store = $this->createStore($connection);

        $this->expectException(RedisException::class);
        $this->expectExceptionMessage('CLUSTERDOWN');

        $store->putMany(['key1' => 'value1', 'key2' => 'value2'], 60);
    }

    public function testManyThrowsOnRedisError(): void
    {
        $connection = $this->mockConnection();
        $connection->shouldReceive('mget')
            ->andThrow(new RedisException('LOADING Redis is loading the dataset in memory'));

        $store = $this->createStore($connection);

        $this->expectException(RedisException::class);
        $this->expectExceptionMessage('LOADING');

        $store->many(['key1', 'key2']);
    }

    // =========================================================================
    // STORE-LEVEL FLUSH
    // =========================================================================

    public function testFlushThrowsOnRedisError(): void
    {
        $connection = $this->mockConnection();
        $connection->shouldReceive('flushdb')
            ->andThrow(new RedisException('MISCONF Redis is configured to save RDB snapshots'));

        $store = $this->createStore($connection);

        $this->expectException(RedisException::class);
        $this->expectExceptionMessage('MISCONF');

        $store->flush();
    }
}
