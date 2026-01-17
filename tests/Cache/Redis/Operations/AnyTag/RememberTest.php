<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis\Operations\AnyTag;

use Hypervel\Tests\Cache\Redis\RedisCacheTestCase;
use RuntimeException;

/**
 * Tests for the AnyTag Remember operation.
 *
 * Tests the single-connection optimization that performs GET and conditional
 * tagged PUT using Lua scripts (standard mode) or sequential commands (cluster mode).
 *
 * On cache miss, creates:
 * 1. The cache key with TTL (SETEX)
 * 2. A reverse index SET tracking which tags this key belongs to
 * 3. Hash field entries in each tag's hash with expiration using HSETEX
 * 4. Registry entries (ZADD)
 *
 * @internal
 * @coversNothing
 */
class RememberTest extends RedisCacheTestCase
{
    /**
     * @test
     */
    public function testRememberReturnsExistingValueOnCacheHit(): void
    {
        $connection = $this->mockConnection();

        $connection->shouldReceive('get')
            ->once()
            ->with('prefix:foo')
            ->andReturn(serialize('cached_value'));

        $redis = $this->createStore($connection);
        $redis->setTagMode('any');
        [$value, $wasHit] = $redis->anyTagOps()->remember()->execute('foo', 60, fn () => 'new_value', ['users']);

        $this->assertSame('cached_value', $value);
        $this->assertTrue($wasHit);
    }

    /**
     * @test
     */
    public function testRememberCallsCallbackOnCacheMissUsingLua(): void
    {
        $connection = $this->mockConnection();

        $connection->shouldReceive('get')
            ->once()
            ->with('prefix:foo')
            ->andReturnNull();

        // Uses evalWithShaCache for Lua script
        $connection->shouldReceive('evalWithShaCache')
            ->once()
            ->withArgs(function ($script, $keys, $args) {
                // Verify script contains expected commands
                $this->assertStringContainsString('SETEX', $script);
                $this->assertStringContainsString('HSETEX', $script);
                $this->assertStringContainsString('ZADD', $script);
                $this->assertCount(2, $keys);

                return true;
            })
            ->andReturn(true);

        $callCount = 0;
        $redis = $this->createStore($connection);
        $redis->setTagMode('any');
        [$value, $wasHit] = $redis->anyTagOps()->remember()->execute('foo', 60, function () use (&$callCount) {
            ++$callCount;

            return 'computed_value';
        }, ['users']);

        $this->assertSame('computed_value', $value);
        $this->assertFalse($wasHit);
        $this->assertSame(1, $callCount);
    }

    /**
     * @test
     */
    public function testRememberUsesEvalWithShaCacheOnMiss(): void
    {
        $connection = $this->mockConnection();

        $connection->shouldReceive('get')
            ->once()
            ->andReturnNull();

        // evalWithShaCache is called
        $connection->shouldReceive('evalWithShaCache')
            ->once()
            ->andReturn(true);

        $redis = $this->createStore($connection);
        $redis->setTagMode('any');
        [$value, $wasHit] = $redis->anyTagOps()->remember()->execute('foo', 60, fn () => 'value', ['users']);

        $this->assertSame('value', $value);
        $this->assertFalse($wasHit);
    }

    /**
     * @test
     */
    public function testRememberDoesNotCallCallbackOnCacheHit(): void
    {
        $connection = $this->mockConnection();

        $connection->shouldReceive('get')
            ->once()
            ->with('prefix:foo')
            ->andReturn(serialize('existing_value'));

        $callCount = 0;
        $redis = $this->createStore($connection);
        $redis->setTagMode('any');
        [$value, $wasHit] = $redis->anyTagOps()->remember()->execute('foo', 60, function () use (&$callCount) {
            ++$callCount;

            return 'new_value';
        }, ['users']);

        $this->assertSame('existing_value', $value);
        $this->assertTrue($wasHit);
        $this->assertSame(0, $callCount, 'Callback should not be called on cache hit');
    }

    /**
     * @test
     */
    public function testRememberWithMultipleTags(): void
    {
        $connection = $this->mockConnection();

        $connection->shouldReceive('get')
            ->once()
            ->andReturnNull();

        // Verify multiple tags are passed in the Lua script args
        $connection->shouldReceive('evalWithShaCache')
            ->once()
            ->withArgs(function ($script, $keys, $args) {
                // Tags are in the args array
                $this->assertContains('users', $args);
                $this->assertContains('posts', $args);
                $this->assertContains('comments', $args);

                return true;
            })
            ->andReturn(true);

        $redis = $this->createStore($connection);
        $redis->setTagMode('any');
        [$value, $wasHit] = $redis->anyTagOps()->remember()->execute(
            'foo',
            60,
            fn () => 'value',
            ['users', 'posts', 'comments']
        );

        $this->assertSame('value', $value);
        $this->assertFalse($wasHit);
    }

    /**
     * @test
     */
    public function testRememberPropagatesExceptionFromCallback(): void
    {
        $connection = $this->mockConnection();

        $connection->shouldReceive('get')
            ->once()
            ->andReturnNull();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Callback failed');

        $redis = $this->createStore($connection);
        $redis->setTagMode('any');
        $redis->anyTagOps()->remember()->execute('foo', 60, function () {
            throw new RuntimeException('Callback failed');
        }, ['users']);
    }

    /**
     * @test
     */
    public function testRememberUsesSequentialCommandsInClusterMode(): void
    {
        $connection = $this->mockClusterConnection();

        $connection->shouldReceive('get')
            ->once()
            ->with('prefix:foo')
            ->andReturnNull();

        // In cluster mode, uses sequential commands instead of Lua

        // Get old tags from reverse index
        $connection->shouldReceive('smembers')
            ->once()
            ->andReturn([]);

        // SETEX for the value
        $connection->shouldReceive('setex')
            ->once()
            ->andReturn(true);

        // Multi for reverse index update - return same connection for chaining
        $connection->shouldReceive('multi')->andReturn($connection);
        $connection->shouldReceive('del')->andReturn($connection);
        $connection->shouldReceive('sadd')->andReturn($connection);
        $connection->shouldReceive('expire')->andReturn($connection);
        $connection->shouldReceive('exec')->andReturn([1, 1, 1]);

        // HSETEX for each tag
        $connection->shouldReceive('hsetex')
            ->twice()
            ->andReturn(true);

        // ZADD for registry
        $connection->shouldReceive('zadd')
            ->once()
            ->andReturn(2);

        $redis = $this->createStore($connection);
        $redis->setTagMode('any');
        [$value, $wasHit] = $redis->anyTagOps()->remember()->execute(
            'foo',
            60,
            fn () => 'value',
            ['users', 'posts']
        );

        $this->assertSame('value', $value);
        $this->assertFalse($wasHit);
    }

    /**
     * @test
     */
    public function testRememberWithNumericValue(): void
    {
        $connection = $this->mockConnection();

        $connection->shouldReceive('get')
            ->once()
            ->andReturnNull();

        $connection->shouldReceive('evalWithShaCache')
            ->once()
            ->andReturn(true);

        $redis = $this->createStore($connection);
        $redis->setTagMode('any');
        [$value, $wasHit] = $redis->anyTagOps()->remember()->execute('foo', 60, fn () => 42, ['users']);

        $this->assertSame(42, $value);
        $this->assertFalse($wasHit);
    }

    /**
     * @test
     */
    public function testRememberHandlesFalseReturnFromGet(): void
    {
        $connection = $this->mockConnection();

        // Redis returns false for non-existent keys
        $connection->shouldReceive('get')
            ->once()
            ->with('prefix:foo')
            ->andReturn(false);

        $connection->shouldReceive('evalWithShaCache')
            ->once()
            ->andReturn(true);

        $redis = $this->createStore($connection);
        $redis->setTagMode('any');
        [$value, $wasHit] = $redis->anyTagOps()->remember()->execute('foo', 60, fn () => 'computed', ['users']);

        $this->assertSame('computed', $value);
        $this->assertFalse($wasHit);
    }

    /**
     * @test
     */
    public function testRememberWithEmptyTags(): void
    {
        $connection = $this->mockConnection();

        $connection->shouldReceive('get')
            ->once()
            ->andReturnNull();

        // With empty tags, should still use Lua script but with no tags in args
        $connection->shouldReceive('evalWithShaCache')
            ->once()
            ->withArgs(function ($script, $keys, $args) {
                // When tags are empty, the tags portion of args should be at the end
                // The args structure is: [value, ttl, tagPrefix, registryKey, time, rawKey, tagHashSuffix, ...tags]
                // With no tags, $args[7...] should be empty
                // We just verify the script is called; the operation handles empty tags internally
                return true;
            })
            ->andReturn(true);

        $redis = $this->createStore($connection);
        $redis->setTagMode('any');
        [$value, $wasHit] = $redis->anyTagOps()->remember()->execute('foo', 60, fn () => 'bar', []);

        $this->assertSame('bar', $value);
        $this->assertFalse($wasHit);
    }
}
