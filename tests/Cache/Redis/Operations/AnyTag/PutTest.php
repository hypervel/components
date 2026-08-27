<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis\Operations\AnyTag;

use Hypervel\Tests\Cache\Redis\RedisCacheTestCase;

/**
 * Tests for the Put operation (union tags).
 */
class PutTest extends RedisCacheTestCase
{
    /**
     * @test
     */
    public function testPutWithTagsUsesLuaScriptInStandardMode(): void
    {
        $connection = $this->mockConnection();

        // Standard mode uses Lua script via evalWithShaCache
        $connection->shouldReceive('evalWithShaCache')
            ->once()
            ->withArgs(function ($script, $keys, $args) {
                // Verify Lua script contains expected commands
                $this->assertStringContainsString('SETEX', $script);
                $this->assertStringContainsString('HSETEX', $script);
                $this->assertStringContainsString('ZADD', $script);
                $this->assertStringContainsString('SMEMBERS', $script);
                // 2 keys: cache key + reverse index key
                $this->assertCount(2, $keys);

                return true;
            })
            ->andReturn(true);

        $redis = $this->createStore($connection);
        $redis->setTagMode('any');
        $result = $redis->anyTagOps()->put()->execute('foo', 'bar', 60, ['users', 'posts']);
        $this->assertTrue($result);
    }

    /**
     * @test
     */
    public function testPutWithTagsUsesSequentialCommandsInClusterMode(): void
    {
        [$redis, , $connection] = $this->createClusterStore(tagMode: 'any');

        // Cluster mode expectations
        $connection->shouldReceive('smembers')->once()->andReturn([]);
        $connection->shouldReceive('setex')->once()->with('prefix:foo', 60, serialize('bar'))->andReturn(true);

        // Multi for reverse index
        $connection->shouldReceive('multi')->andReturn($connection);
        $connection->shouldReceive('del')->andReturn($connection);
        $connection->shouldReceive('sadd')->andReturn($connection);
        $connection->shouldReceive('expire')->andReturn($connection);
        $connection->shouldReceive('exec')->andReturn([true, true, true]);

        // HSETEX for tag hashes (2 tags) - use withAnyArgs to bypass type checking
        $connection->shouldReceive('hsetex')->withAnyArgs()->twice()->andReturn(true);

        // ZADD for registry - use withAnyArgs to handle variable args
        $connection->shouldReceive('zadd')->withAnyArgs()->once()->andReturn(2);

        $result = $redis->anyTagOps()->put()->execute('foo', 'bar', 60, ['users', 'posts']);
        $this->assertTrue($result);
    }

    public function testPutNormalizesExpiringMetadataInClusterMode(): void
    {
        [$redis, , $connection] = $this->createClusterStore(tagMode: 'any');
        $startedAt = time();

        $connection->shouldReceive('smembers')->once()->with('prefix:foo:_any:tags')->andReturn([]);
        $connection->shouldReceive('setex')->once()->with('prefix:foo', 1, serialize('bar'))->andReturn(true);
        $connection->shouldReceive('multi')->once()->andReturnSelf();
        $connection->shouldReceive('del')->once()->with('prefix:foo:_any:tags')->andReturnSelf();
        $connection->shouldReceive('sadd')->once()->with('prefix:foo:_any:tags', 'users')->andReturnSelf();
        $connection->shouldReceive('expire')->once()->with('prefix:foo:_any:tags', 1)->andReturnSelf();
        $connection->shouldReceive('exec')->once()->andReturn([]);
        $connection->shouldReceive('hsetex')
            ->once()
            ->with('prefix:_any:tag:users:entries', ['foo' => '1'], ['EX' => 1])
            ->andReturn(1);
        $connection->shouldReceive('zadd')
            ->once()
            ->withArgs(function (string $key, array $options, int $expiresAt, string $tag) use ($startedAt): bool {
                $this->assertSame('prefix:_any:tag:registry', $key);
                $this->assertSame(['GT'], $options);
                $this->assertGreaterThan($startedAt, $expiresAt);
                $this->assertSame('users', $tag);

                return true;
            })
            ->andReturn(1);

        $this->assertTrue($redis->anyTagOps()->put()->execute('foo', 'bar', -60, ['users']));
    }

    /**
     * @test
     */
    public function testPutWithTagsHandlesEmptyTags(): void
    {
        $connection = $this->mockConnection();

        $connection->shouldReceive('evalWithShaCache')
            ->once()
            ->andReturn(true);

        $redis = $this->createStore($connection);
        $redis->setTagMode('any');
        $result = $redis->anyTagOps()->put()->execute('foo', 'bar', 60, []);
        $this->assertTrue($result);
    }

    /**
     * @test
     */
    public function testPutWithTagsWithNumericValue(): void
    {
        $connection = $this->mockConnection();

        $connection->shouldReceive('evalWithShaCache')
            ->once()
            ->withArgs(function ($script, $keys, $args) {
                // Numeric values should be passed as strings in args
                // Args array contains: value, ttl, tagPrefix, registryKey, currentTime, rawKey, tagHashSuffix, ...tags
                $this->assertIsString($args[0]); // Serialized value
                return true;
            })
            ->andReturn(true);

        $redis = $this->createStore($connection);
        $redis->setTagMode('any');
        $result = $redis->anyTagOps()->put()->execute('foo', 42, 60, ['numbers']);
        $this->assertTrue($result);
    }
}
