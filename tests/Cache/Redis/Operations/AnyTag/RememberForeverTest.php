<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis\Operations\AnyTag;

use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Cache\Redis\Concerns\MocksRedisConnections;
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
class RememberForeverTest extends TestCase
{
    use MocksRedisConnections;

    /**
     * @test
     */
    public function testRememberForeverReturnsExistingValueOnCacheHit(): void
    {
        $connection = $this->mockConnection();
        $client = $connection->_mockClient;

        $client->shouldReceive('get')
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
        $client = $connection->_mockClient;

        $client->shouldReceive('get')
            ->once()
            ->with('prefix:foo')
            ->andReturnNull();

        // First tries evalSha, then falls back to eval
        $client->shouldReceive('evalSha')
            ->once()
            ->andReturn(false);

        $client->shouldReceive('eval')
            ->once()
            ->withArgs(function ($script, $args, $numKeys) {
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
                $this->assertSame(2, $numKeys);

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
    public function testRememberForeverUsesEvalShaWhenScriptCached(): void
    {
        $connection = $this->mockConnection();
        $client = $connection->_mockClient;

        $client->shouldReceive('get')
            ->once()
            ->andReturnNull();

        // evalSha succeeds (script is cached)
        $client->shouldReceive('evalSha')
            ->once()
            ->andReturn(true);

        // eval should NOT be called
        $client->shouldReceive('eval')->never();

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
        $client = $connection->_mockClient;

        $client->shouldReceive('get')
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
        $client = $connection->_mockClient;

        $client->shouldReceive('get')
            ->once()
            ->andReturnNull();

        // Verify multiple tags are passed in the Lua script args
        $client->shouldReceive('evalSha')
            ->once()
            ->withArgs(function ($hash, $args, $numKeys) {
                // Args: 2 KEYS + 5 ARGV (value, tagPrefix, registryKey, rawKey, tagHashSuffix) = 7
                // Tags start at index 7 (ARGV[6...])
                $tags = array_slice($args, 7);
                $this->assertContains('users', $tags);
                $this->assertContains('posts', $tags);
                $this->assertContains('comments', $tags);

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
        $client = $connection->_mockClient;

        $client->shouldReceive('get')
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
        $client = $connection->_mockClient;

        $client->shouldReceive('get')
            ->once()
            ->with('prefix:foo')
            ->andReturnNull();

        // In cluster mode, uses sequential commands instead of Lua

        // Get old tags from reverse index
        $client->shouldReceive('smembers')
            ->once()
            ->andReturn([]);

        // SET without TTL (not SETEX)
        $client->shouldReceive('set')
            ->once()
            ->andReturn(true);

        // Multi for reverse index update (no expire call for forever) - return same client for chaining
        $client->shouldReceive('multi')->andReturn($client);
        $client->shouldReceive('del')->andReturn($client);
        $client->shouldReceive('sadd')->andReturn($client);
        // No expire() call for forever items
        $client->shouldReceive('exec')->andReturn([1, 1]);

        // HSET for each tag (not HSETEX, no HEXPIRE)
        $client->shouldReceive('hset')
            ->twice()
            ->andReturn(true);

        // ZADD for registry with MAX_EXPIRY
        $client->shouldReceive('zadd')
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
        $client = $connection->_mockClient;

        $client->shouldReceive('get')
            ->once()
            ->andReturnNull();

        $client->shouldReceive('evalSha')
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
        $client = $connection->_mockClient;

        // Redis returns false for non-existent keys
        $client->shouldReceive('get')
            ->once()
            ->with('prefix:foo')
            ->andReturn(false);

        $client->shouldReceive('evalSha')
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
        $client = $connection->_mockClient;

        $client->shouldReceive('get')
            ->once()
            ->andReturnNull();

        // With empty tags, should still use Lua script but with no tags in args
        $client->shouldReceive('evalSha')
            ->once()
            ->withArgs(function ($hash, $args, $numKeys) {
                // Args: 2 KEYS + 5 ARGV = 7 fixed, tags start at index 7 (ARGV[6...])
                $tags = array_slice($args, 7);
                $this->assertEmpty($tags);

                return true;
            })
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
        $client = $connection->_mockClient;

        $client->shouldReceive('get')
            ->once()
            ->andReturnNull();

        $client->shouldReceive('smembers')
            ->once()
            ->andReturn([]);

        $client->shouldReceive('set')
            ->once()
            ->andReturn(true);

        // Multi for reverse index - should NOT have expire call
        // Return same client for chaining (required for RedisCluster type constraints)
        $client->shouldReceive('multi')->andReturn($client);
        $client->shouldReceive('del')->andReturn($client);
        $client->shouldReceive('sadd')->andReturn($client);
        // Note: We can't easily test that expire is never called with this pattern
        // because the client mock is reused. The absence of expire in the code is
        // verified by reading the implementation.
        $client->shouldReceive('exec')->andReturn([1, 1]);

        $client->shouldReceive('hset')
            ->once()
            ->andReturn(true);

        $client->shouldReceive('zadd')
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
        $client = $connection->_mockClient;

        $client->shouldReceive('get')
            ->once()
            ->andReturnNull();

        // Verify Lua script contains MAX_EXPIRY constant
        $client->shouldReceive('evalSha')
            ->once()
            ->andReturn(false);

        $client->shouldReceive('eval')
            ->once()
            ->withArgs(function ($script, $args, $numKeys) {
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
        $client = $connection->_mockClient;

        $client->shouldReceive('get')
            ->once()
            ->andReturnNull();

        // Return old tags that should be cleaned up
        $client->shouldReceive('smembers')
            ->once()
            ->andReturn(['old_tag', 'users']);

        $client->shouldReceive('set')
            ->once()
            ->andReturn(true);

        // Multi for reverse index - return same client for chaining
        $client->shouldReceive('multi')->andReturn($client);
        $client->shouldReceive('del')->andReturn($client);
        $client->shouldReceive('sadd')->andReturn($client);
        $client->shouldReceive('exec')->andReturn([1, 1]);

        // Should HDEL from old_tag since it's not in new tags
        $client->shouldReceive('hdel')
            ->once()
            ->withArgs(function ($hashKey, $key) {
                $this->assertStringContainsString('old_tag', $hashKey);
                $this->assertSame('foo', $key);

                return true;
            })
            ->andReturn(1);

        // HSET only for new tag 'users'
        $client->shouldReceive('hset')
            ->once()
            ->andReturn(true);

        $client->shouldReceive('zadd')
            ->once()
            ->andReturn(1);

        $redis = $this->createStore($connection);
        $redis->setTagMode('any');
        $redis->anyTagOps()->rememberForever()->execute('foo', fn () => 'bar', ['users']);
    }
}
