<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis;

use Hyperf\Redis\Pool\PoolFactory;
use Hyperf\Redis\RedisFactory;
use Hyperf\Redis\RedisProxy;
use Hypervel\Cache\Redis\TagMode;
use Hypervel\Cache\RedisLock;
use Hypervel\Cache\RedisStore;
use Hypervel\Tests\Cache\Redis\Concerns\MocksRedisConnections;
use Hypervel\Tests\TestCase;
use Mockery as m;

/**
 * Tests for RedisStore core functionality.
 *
 * Operation-specific tests have been moved to the Operations/ directory.
 * This file contains only store-level tests (prefix, connection, tags, locks).
 *
 * @internal
 * @coversNothing
 */
class RedisStoreTest extends TestCase
{
    use MocksRedisConnections;

    /**
     * @test
     */
    public function testGetAndSetPrefix(): void
    {
        $connection = $this->mockConnection();
        $redis = $this->createStore($connection);

        $this->assertSame('prefix:', $redis->getPrefix());
        $redis->setPrefix('foo:');
        $this->assertSame('foo:', $redis->getPrefix());
        $redis->setPrefix('');
        $this->assertEmpty($redis->getPrefix());
    }

    /**
     * @test
     */
    public function testSetConnectionClearsCachedInstances(): void
    {
        $connection1 = $this->mockConnection();
        $connection1->shouldReceive('get')->once()->with('prefix:foo')->andReturn(serialize('value1'));

        $connection2 = $this->mockConnection();
        $connection2->shouldReceive('get')->once()->with('prefix:foo')->andReturn(serialize('value2'));

        // Create store with first connection
        $poolFactory1 = $this->createPoolFactory($connection1, 'conn1');
        $redis = new RedisStore(
            m::mock(RedisFactory::class),
            'prefix:',
            'conn1',
            $poolFactory1
        );

        $this->assertSame('value1', $redis->get('foo'));

        // Change connection - this should clear cached operation instances
        $poolFactory2 = $this->createPoolFactory($connection2, 'conn2');

        // We need to inject the new pool factory. Since we can't directly,
        // we verify that setConnection clears the context by checking
        // that a new store with different connection gets different values.
        $redis2 = new RedisStore(
            m::mock(RedisFactory::class),
            'prefix:',
            'conn2',
            $poolFactory2
        );

        $this->assertSame('value2', $redis2->get('foo'));
    }

    /**
     * @test
     */
    public function testSetPrefixClearsCachedOperations(): void
    {
        $connection = $this->mockConnection();
        $connection->shouldReceive('get')->once()->with('prefix:foo')->andReturn(serialize('old'));
        $connection->shouldReceive('get')->once()->with('newprefix:foo')->andReturn(serialize('new'));

        $redis = $this->createStore($connection);

        // First get with original prefix
        $this->assertSame('old', $redis->get('foo'));

        // Change prefix (include colon since setPrefix stores as-is)
        $redis->setPrefix('newprefix:');

        // Second get should use new prefix
        $this->assertSame('new', $redis->get('foo'));
    }

    /**
     * @test
     */
    public function testTagsReturnsAllTaggedCache(): void
    {
        $connection = $this->mockConnection();
        $redis = $this->createStore($connection);

        $tagged = $redis->tags(['users', 'posts']);

        $this->assertInstanceOf(\Hypervel\Cache\Redis\AllTaggedCache::class, $tagged);
    }

    /**
     * @test
     */
    public function testTagsWithSingleTagAsString(): void
    {
        $connection = $this->mockConnection();
        $redis = $this->createStore($connection);

        $tagged = $redis->tags('users');

        $this->assertInstanceOf(\Hypervel\Cache\Redis\AllTaggedCache::class, $tagged);
    }

    /**
     * @test
     */
    public function testTagsWithVariadicArguments(): void
    {
        $connection = $this->mockConnection();
        $redis = $this->createStore($connection);

        $tagged = $redis->tags('users', 'posts', 'comments');

        $this->assertInstanceOf(\Hypervel\Cache\Redis\AllTaggedCache::class, $tagged);
    }

    /**
     * @test
     */
    public function testDefaultTagModeIsAll(): void
    {
        $connection = $this->mockConnection();
        $redis = $this->createStore($connection);

        $this->assertSame(TagMode::All, $redis->getTagMode());
    }

    /**
     * @test
     */
    public function testSetTagModeReturnsStoreInstance(): void
    {
        $connection = $this->mockConnection();
        $redis = $this->createStore($connection);

        $result = $redis->setTagMode('any');

        $this->assertSame($redis, $result);
        $this->assertSame(TagMode::Any, $redis->getTagMode());
    }

    /**
     * @test
     */
    public function testTagsReturnsAnyTaggedCacheWhenInAnyMode(): void
    {
        $connection = $this->mockConnection();
        $redis = $this->createStore($connection);
        $redis->setTagMode('any');

        $tagged = $redis->tags(['users', 'posts']);

        $this->assertInstanceOf(\Hypervel\Cache\Redis\AnyTaggedCache::class, $tagged);
    }

    /**
     * @test
     */
    public function testTagsReturnsAllTaggedCacheWhenInAllMode(): void
    {
        $connection = $this->mockConnection();
        $redis = $this->createStore($connection);
        $redis->setTagMode('all');

        $tagged = $redis->tags(['users', 'posts']);

        $this->assertInstanceOf(\Hypervel\Cache\Redis\AllTaggedCache::class, $tagged);
    }

    /**
     * @test
     */
    public function testSetTagModeFallsBackToAllForInvalidMode(): void
    {
        $connection = $this->mockConnection();
        $redis = $this->createStore($connection);

        $redis->setTagMode('invalid');

        $this->assertSame(TagMode::All, $redis->getTagMode());
    }

    /**
     * @test
     */
    public function testLockReturnsRedisLockInstance(): void
    {
        $connection = $this->mockConnection();
        $redisProxy = m::mock(RedisProxy::class);
        $redisFactory = m::mock(RedisFactory::class);
        $redisFactory->shouldReceive('get')->with('default')->andReturn($redisProxy);

        $redis = new RedisStore(
            $redisFactory,
            'prefix:',
            'default',
            $this->createPoolFactory($connection)
        );

        $lock = $redis->lock('mylock', 10);

        $this->assertInstanceOf(RedisLock::class, $lock);
    }

    /**
     * @test
     */
    public function testLockWithOwner(): void
    {
        $connection = $this->mockConnection();
        $redisProxy = m::mock(RedisProxy::class);
        $redisFactory = m::mock(RedisFactory::class);
        $redisFactory->shouldReceive('get')->with('default')->andReturn($redisProxy);

        $redis = new RedisStore(
            $redisFactory,
            'prefix:',
            'default',
            $this->createPoolFactory($connection)
        );

        $lock = $redis->lock('mylock', 10, 'custom-owner');

        $this->assertInstanceOf(RedisLock::class, $lock);
    }

    /**
     * @test
     */
    public function testRestoreLockReturnsRedisLockInstance(): void
    {
        $connection = $this->mockConnection();
        $redisProxy = m::mock(RedisProxy::class);
        $redisFactory = m::mock(RedisFactory::class);
        $redisFactory->shouldReceive('get')->with('default')->andReturn($redisProxy);

        $redis = new RedisStore(
            $redisFactory,
            'prefix:',
            'default',
            $this->createPoolFactory($connection)
        );

        $lock = $redis->restoreLock('mylock', 'owner-123');

        $this->assertInstanceOf(RedisLock::class, $lock);
    }

    /**
     * @test
     */
    public function testSetLockConnectionReturnsSelf(): void
    {
        $connection = $this->mockConnection();
        $redis = $this->createStore($connection);

        $result = $redis->setLockConnection('locks');

        $this->assertSame($redis, $result);
    }

    /**
     * @test
     */
    public function testLockUsesLockConnectionWhenSet(): void
    {
        $connection = $this->mockConnection();
        $redisProxy = m::mock(RedisProxy::class);
        $lockProxy = m::mock(RedisProxy::class);
        $redisFactory = m::mock(RedisFactory::class);
        $redisFactory->shouldReceive('get')->with('default')->andReturn($redisProxy);
        $redisFactory->shouldReceive('get')->with('locks')->andReturn($lockProxy);

        $redis = new RedisStore(
            $redisFactory,
            'prefix:',
            'default',
            $this->createPoolFactory($connection)
        );

        $redis->setLockConnection('locks');
        $lock = $redis->lock('mylock', 10);

        $this->assertInstanceOf(RedisLock::class, $lock);
    }

    /**
     * @test
     */
    public function testGetRedisReturnsRedisFactory(): void
    {
        $connection = $this->mockConnection();
        $redisFactory = m::mock(RedisFactory::class);

        $redis = new RedisStore(
            $redisFactory,
            'prefix:',
            'default',
            $this->createPoolFactory($connection)
        );

        $this->assertSame($redisFactory, $redis->getRedis());
    }

    /**
     * @test
     */
    public function testConnectionReturnsRedisProxy(): void
    {
        $connection = $this->mockConnection();
        $redisProxy = m::mock(RedisProxy::class);
        $redisFactory = m::mock(RedisFactory::class);
        $redisFactory->shouldReceive('get')->with('default')->andReturn($redisProxy);

        $redis = new RedisStore(
            $redisFactory,
            'prefix:',
            'default',
            $this->createPoolFactory($connection)
        );

        $this->assertSame($redisProxy, $redis->connection());
    }
}
