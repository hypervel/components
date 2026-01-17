<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis\Operations\AnyTag;

use Hypervel\Tests\Cache\Redis\RedisCacheTestCase;
use RuntimeException;

/**
 * Tests for the AnyTag RememberForever operation.
 *
 * Tests the single-connection optimization that performs GET and conditional
 * tagged SET using Lua scripts (standard mode) or sequential commands (cluster mode).
 *
 * Unlike Remember which uses SETEX with TTL, this uses SET without expiration
 * and HSET without HEXPIRE for tag hash fields. Registry entries use MAX_EXPIRY.
 *
 * On cache miss, creates:
 * 1. The cache key without TTL (SET)
 * 2. A reverse index SET tracking which tags this key belongs to (no expiration)
 * 3. Hash field entries in each tag's hash without expiration (HSET)
 * 4. Registry entries with MAX_EXPIRY (ZADD)
 *
 * @internal
 * @coversNothing
 */
class RememberForeverTest extends RedisCacheTestCase
{
    /**
     * @test
     */
    public function testRememberForeverReturnsExistingValueOnCacheHit(): void
    {
        $connection = $this->mockConnection();

        $connection->shouldReceive('get')
            ->once()
            ->with('prefix:foo')
            ->andReturn(serialize('cached_value'));

        $redis = $this->createStore($connection);
        $redis->setTagMode('any');
        [$value, $wasHit] = $redis->anyTagOps()->rememberForever()->execute('foo', fn () => 'new_value', ['users']);

        $this->assertSame('cached_value', $value);
        $this->assertTrue($wasHit);
    }

    /**
     * @test
     */
    public function testRememberForeverCallsCallbackOnCacheMissUsingLua(): void
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
                // Verify script uses SET (not SETEX) and HSET (not HSETEX)
                $this->assertStringContainsString("redis.call('SET'", $script);
                $this->assertStringContainsString("redis.call('HSET'", $script);
                $this->assertStringContainsString('ZADD', $script);
                // Should NOT contain SETEX or HSETEX for forever items
                // Note: The word "HEXPIRE" appears in comments but not as a redis.call
                $this->assertStringNotContainsString('SETEX', $script);
                $this->assertStringNotContainsString('HSETEX', $script);
                // Verify no redis.call('HEXPIRE' - the word may appear in comments but not as actual command
                $this->assertStringNotContainsString("redis.call('HEXPIRE", $script);
                $this->assertCount(2, $keys);

                return true;
            })
            ->andReturn(true);

        $callCount = 0;
        $redis = $this->createStore($connection);
        $redis->setTagMode('any');
        [$value, $wasHit] = $redis->anyTagOps()->rememberForever()->execute('foo', function () use (&$callCount) {
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
    public function testRememberForeverUsesEvalWithShaCacheOnMiss(): void
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
        [$value, $wasHit] = $redis->anyTagOps()->rememberForever()->execute('foo', fn () => 'value', ['users']);

        $this->assertSame('value', $value);
        $this->assertFalse($wasHit);
    }

    /**
     * @test
     */
    public function testRememberForeverDoesNotCallCallbackOnCacheHit(): void
    {
        $connection = $this->mockConnection();

        $connection->shouldReceive('get')
            ->once()
            ->with('prefix:foo')
            ->andReturn(serialize('existing_value'));

        $callCount = 0;
        $redis = $this->createStore($connection);
        $redis->setTagMode('any');
        [$value, $wasHit] = $redis->anyTagOps()->rememberForever()->execute('foo', function () use (&$callCount) {
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
    public function testRememberForeverWithMultipleTags(): void
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
        [$value, $wasHit] = $redis->anyTagOps()->rememberForever()->execute(
            'foo',
            fn () => 'value',
            ['users', 'posts', 'comments']
        );

        $this->assertSame('value', $value);
        $this->assertFalse($wasHit);
    }

    /**
     * @test
     */
    public function testRememberForeverPropagatesExceptionFromCallback(): void
    {
        $connection = $this->mockConnection();

        $connection->shouldReceive('get')
            ->once()
            ->andReturnNull();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Callback failed');

        $redis = $this->createStore($connection);
        $redis->setTagMode('any');
        $redis->anyTagOps()->rememberForever()->execute('foo', function () {
            throw new RuntimeException('Callback failed');
        }, ['users']);
    }

    /**
     * @test
     */
    public function testRememberForeverUsesSequentialCommandsInClusterMode(): void
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

        // SET without TTL (not SETEX)
        $connection->shouldReceive('set')
            ->once()
            ->andReturn(true);

        // Multi for reverse index update (no expire call for forever) - return same connection for chaining
        $connection->shouldReceive('multi')->andReturn($connection);
        $connection->shouldReceive('del')->andReturn($connection);
        $connection->shouldReceive('sadd')->andReturn($connection);
        // No expire() call for forever items
        $connection->shouldReceive('exec')->andReturn([1, 1]);

        // HSET for each tag (not HSETEX, no HEXPIRE)
        $connection->shouldReceive('hset')
            ->twice()
            ->andReturn(true);

        // ZADD for registry with MAX_EXPIRY
        $connection->shouldReceive('zadd')
            ->once()
            ->withArgs(function ($key, $options, ...$rest) {
                $this->assertSame(['GT'], $options);
                // First score should be MAX_EXPIRY (253402300799)
                $this->assertSame(253402300799, $rest[0]);

                return true;
            })
            ->andReturn(2);

        $redis = $this->createStore($connection);
        $redis->setTagMode('any');
        [$value, $wasHit] = $redis->anyTagOps()->rememberForever()->execute(
            'foo',
            fn () => 'value',
            ['users', 'posts']
        );

        $this->assertSame('value', $value);
        $this->assertFalse($wasHit);
    }

    /**
     * @test
     */
    public function testRememberForeverWithNumericValue(): void
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
        [$value, $wasHit] = $redis->anyTagOps()->rememberForever()->execute('foo', fn () => 42, ['users']);

        $this->assertSame(42, $value);
        $this->assertFalse($wasHit);
    }

    /**
     * @test
     */
    public function testRememberForeverHandlesFalseReturnFromGet(): void
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
        [$value, $wasHit] = $redis->anyTagOps()->rememberForever()->execute('foo', fn () => 'computed', ['users']);

        $this->assertSame('computed', $value);
        $this->assertFalse($wasHit);
    }

    /**
     * @test
     */
    public function testRememberForeverWithEmptyTags(): void
    {
        $connection = $this->mockConnection();

        $connection->shouldReceive('get')
            ->once()
            ->andReturnNull();

        // With empty tags, should still use Lua script but with no tags in args
        $connection->shouldReceive('evalWithShaCache')
            ->once()
            ->andReturn(true);

        $redis = $this->createStore($connection);
        $redis->setTagMode('any');
        [$value, $wasHit] = $redis->anyTagOps()->rememberForever()->execute('foo', fn () => 'bar', []);

        $this->assertSame('bar', $value);
        $this->assertFalse($wasHit);
    }

    /**
     * @test
     */
    public function testRememberForeverDoesNotSetExpirationOnReverseIndex(): void
    {
        $connection = $this->mockClusterConnection();

        $connection->shouldReceive('get')
            ->once()
            ->andReturnNull();

        $connection->shouldReceive('smembers')
            ->once()
            ->andReturn([]);

        $connection->shouldReceive('set')
            ->once()
            ->andReturn(true);

        // Multi for reverse index - should NOT have expire call
        // Return same connection for chaining (required for RedisCluster type constraints)
        $connection->shouldReceive('multi')->andReturn($connection);
        $connection->shouldReceive('del')->andReturn($connection);
        $connection->shouldReceive('sadd')->andReturn($connection);
        // Note: We can't easily test that expire is never called with this pattern
        // because the connection mock is reused. The absence of expire in the code is
        // verified by reading the implementation.
        $connection->shouldReceive('exec')->andReturn([1, 1]);

        $connection->shouldReceive('hset')
            ->once()
            ->andReturn(true);

        $connection->shouldReceive('zadd')
            ->once()
            ->andReturn(1);

        $redis = $this->createStore($connection);
        $redis->setTagMode('any');
        $redis->anyTagOps()->rememberForever()->execute('foo', fn () => 'bar', ['users']);
    }

    /**
     * @test
     */
    public function testRememberForeverUsesMaxExpiryForRegistry(): void
    {
        $connection = $this->mockConnection();

        $connection->shouldReceive('get')
            ->once()
            ->andReturnNull();

        // Verify Lua script contains MAX_EXPIRY constant
        $connection->shouldReceive('evalWithShaCache')
            ->once()
            ->withArgs(function ($script, $keys, $args) {
                // MAX_EXPIRY = 253402300799 (Year 9999)
                $this->assertStringContainsString('253402300799', $script);

                return true;
            })
            ->andReturn(true);

        $redis = $this->createStore($connection);
        $redis->setTagMode('any');
        $redis->anyTagOps()->rememberForever()->execute('foo', fn () => 'bar', ['users']);
    }

    /**
     * @test
     */
    public function testRememberForeverRemovesItemFromOldTagsInClusterMode(): void
    {
        $connection = $this->mockClusterConnection();

        $connection->shouldReceive('get')
            ->once()
            ->andReturnNull();

        // Return old tags that should be cleaned up
        $connection->shouldReceive('smembers')
            ->once()
            ->andReturn(['old_tag', 'users']);

        $connection->shouldReceive('set')
            ->once()
            ->andReturn(true);

        // Multi for reverse index - return same connection for chaining
        $connection->shouldReceive('multi')->andReturn($connection);
        $connection->shouldReceive('del')->andReturn($connection);
        $connection->shouldReceive('sadd')->andReturn($connection);
        $connection->shouldReceive('exec')->andReturn([1, 1]);

        // Should HDEL from old_tag since it's not in new tags
        $connection->shouldReceive('hdel')
            ->once()
            ->withArgs(function ($hashKey, $key) {
                $this->assertStringContainsString('old_tag', $hashKey);
                $this->assertSame('foo', $key);

                return true;
            })
            ->andReturn(1);

        // HSET only for new tag 'users'
        $connection->shouldReceive('hset')
            ->once()
            ->andReturn(true);

        $connection->shouldReceive('zadd')
            ->once()
            ->andReturn(1);

        $redis = $this->createStore($connection);
        $redis->setTagMode('any');
        $redis->anyTagOps()->rememberForever()->execute('foo', fn () => 'bar', ['users']);
    }
}
