<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis\Operations\AnyTag;

use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Cache\Redis\Concerns\MocksRedisConnections;
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
class RememberTest extends TestCase
{
    use MocksRedisConnections;

    /**
     * @test
     */
    public function testRememberReturnsExistingValueOnCacheHit(): void
    {
        $connection = $this->mockConnection();
        $client = $connection->_mockClient;

        $client->shouldReceive('get')
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
                // Verify script contains expected commands
                $this->assertStringContainsString('SETEX', $script);
                $this->assertStringContainsString('HSETEX', $script);
                $this->assertStringContainsString('ZADD', $script);
                $this->assertSame(2, $numKeys);

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
    public function testRememberUsesEvalShaWhenScriptCached(): void
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
        $client = $connection->_mockClient;

        $client->shouldReceive('get')
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
        $client = $connection->_mockClient;

        $client->shouldReceive('get')
            ->once()
            ->andReturnNull();

        // Verify multiple tags are passed in the Lua script args
        $client->shouldReceive('evalSha')
            ->once()
            ->withArgs(function ($hash, $args, $numKeys) {
                // Args: 2 KEYS + 7 ARGV = 9 fixed, tags start at index 9 (ARGV[8...])
                $tags = array_slice($args, 9);
                $this->assertContains('users', $tags);
                $this->assertContains('posts', $tags);
                $this->assertContains('comments', $tags);

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
        $client = $connection->_mockClient;

        $client->shouldReceive('get')
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

        // SETEX for the value
        $client->shouldReceive('setex')
            ->once()
            ->andReturn(true);

        // Multi for reverse index update - return same client for chaining
        $client->shouldReceive('multi')->andReturn($client);
        $client->shouldReceive('del')->andReturn($client);
        $client->shouldReceive('sadd')->andReturn($client);
        $client->shouldReceive('expire')->andReturn($client);
        $client->shouldReceive('exec')->andReturn([1, 1, 1]);

        // HSETEX for each tag
        $client->shouldReceive('hsetex')
            ->twice()
            ->andReturn(true);

        // ZADD for registry
        $client->shouldReceive('zadd')
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
        $client = $connection->_mockClient;

        $client->shouldReceive('get')
            ->once()
            ->andReturnNull();

        $client->shouldReceive('evalSha')
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
        $client = $connection->_mockClient;

        $client->shouldReceive('get')
            ->once()
            ->andReturnNull();

        // With empty tags, should still use Lua script but with no tags in args
        $client->shouldReceive('evalSha')
            ->once()
            ->withArgs(function ($hash, $args, $numKeys) {
                // Args: 2 KEYS + 7 ARGV (value, ttl, tagPrefix, registryKey, time, rawKey, tagHashSuffix) = 9
                // Tags start at index 9 (ARGV[8...])
                $tags = array_slice($args, 9);
                $this->assertEmpty($tags);

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
