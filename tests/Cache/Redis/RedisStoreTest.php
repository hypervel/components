<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis;

use Hypervel\Cache\RedisLock;
use Hypervel\Cache\RedisStore;
use Hypervel\Cache\TagMode;
use Hypervel\Contracts\Redis\Factory as Redis;
use Hypervel\Redis\RedisProxy;
use Hypervel\Support\ClassInvoker;
use Mockery as m;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;

/**
 * Tests for RedisStore core functionality.
 *
 * Operation-specific tests have been moved to the Operations/ directory.
 * This file contains only store-level tests (prefix, connection, tags, locks).
 */
class RedisStoreTest extends RedisCacheTestCase
{
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

        $redisProxy1 = m::mock(RedisProxy::class);
        $redisProxy1->shouldReceive('withConnection')
            ->once()
            ->andReturnUsing(fn (callable $callback) => $callback($connection1));

        $redisProxy2 = m::mock(RedisProxy::class);
        $redisProxy2->shouldReceive('withConnection')
            ->once()
            ->andReturnUsing(fn (callable $callback) => $callback($connection2));

        $redisFactory = m::mock(Redis::class);
        $redisFactory->shouldReceive('connection')->once()->with('conn1')->andReturn($redisProxy1);
        $redisFactory->shouldReceive('connection')->once()->with('conn2')->andReturn($redisProxy2);

        $redis = new RedisStore($redisFactory, 'prefix:', 'conn1');

        $this->assertSame('value1', $redis->get('foo'));

        $redis->setConnection('conn2');

        $this->assertSame('value2', $redis->get('foo'));
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

    public function testBootConfigurationMutatorsClearEveryCachedInstance(): void
    {
        $cachedInstances = [
            'context' => 'getContext',
            'serialization' => 'getSerialization',
            'getOperation' => 'getGetOperation',
            'manyOperation' => 'getManyOperation',
            'putOperation' => 'getPutOperation',
            'putManyOperation' => 'getPutManyOperation',
            'addOperation' => 'getAddOperation',
            'foreverOperation' => 'getForeverOperation',
            'forgetOperation' => 'getForgetOperation',
            'touchOperation' => 'getTouchOperation',
            'incrementOperation' => 'getIncrementOperation',
            'decrementOperation' => 'getDecrementOperation',
            'flushOperation' => 'getFlushOperation',
            'anyTagOperations' => 'anyTagOps',
            'allTagOperations' => 'allTagOps',
        ];

        $cachedProperties = [];

        foreach ((new ReflectionClass(RedisStore::class))->getProperties(ReflectionProperty::IS_PRIVATE) as $property) {
            $type = $property->getType();

            if ($type instanceof ReflectionNamedType
                && $type->allowsNull()
                && $property->hasDefaultValue()
                && $property->getDefaultValue() === null) {
                $cachedProperties[] = $property->getName();
            }
        }

        $mappedProperties = array_keys($cachedInstances);
        sort($cachedProperties);
        sort($mappedProperties);

        $this->assertSame(
            $cachedProperties,
            $mappedProperties,
            'A new RedisStore lazy cache needs both a warming accessor here and an invalidation reset.'
        );

        // setLockConnection() is deliberately excluded: lock connections are resolved
        // per call and are not captured by any cached instance.
        $mutators = [
            'setConnection' => static function (RedisStore $store): void {
                $store->setConnection('other');
            },
            'setPrefix' => static function (RedisStore $store): void {
                $store->setPrefix('other:');
            },
            'setTagMode' => static function (RedisStore $store): void {
                $store->setTagMode(TagMode::Any);
            },
        ];

        foreach ($mutators as $method => $mutator) {
            $store = $this->createStore($this->mockConnection());
            $invoker = new ClassInvoker($store);

            foreach ($cachedInstances as $accessor) {
                $invoker->{$accessor}();
            }

            $mutator($store);

            foreach (array_keys($cachedInstances) as $property) {
                $this->assertNull(
                    $invoker->{$property},
                    "{$method} did not clear the cached {$property} instance."
                );
            }
        }
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
    public function testTouchUsesPlainExpireInAllMode(): void
    {
        $connection = $this->mockConnection();
        $connection->shouldReceive('expire')
            ->once()
            ->with('prefix:key', 60)
            ->andReturn(1);

        $redis = $this->createStore($connection);

        $this->assertTrue($redis->touch('key', 60));
    }

    /**
     * @test
     */
    public function testTouchUsesAnyTagMetadataOperationInAnyMode(): void
    {
        $connection = $this->mockConnection();
        $connection->shouldReceive('evalWithShaCache')
            ->once()
            ->andReturn(true);

        $redis = $this->createStore($connection);
        $redis->setTagMode('any');

        $this->assertTrue($redis->touch('key', 60));
    }

    /**
     * @test
     */
    public function testForgetUsesPlainDeleteInAllMode(): void
    {
        $connection = $this->mockConnection();
        $connection->shouldReceive('del')
            ->once()
            ->with('prefix:key')
            ->andReturn(1);

        $redis = $this->createStore($connection);

        $this->assertTrue($redis->forget('key'));
    }

    /**
     * @test
     */
    public function testForgetUsesAnyTagMetadataOperationInAnyMode(): void
    {
        $connection = $this->mockConnection();
        $connection->shouldReceive('evalWithShaCache')
            ->once()
            ->andReturn(1);

        $redis = $this->createStore($connection);
        $redis->setTagMode('any');

        $this->assertTrue($redis->forget('key'));
    }

    /**
     * @test
     */
    public function testLockReturnsRedisLockInstance(): void
    {
        $redisProxy = m::mock(RedisProxy::class);
        $redisFactory = m::mock(Redis::class);
        $redisFactory->shouldReceive('connection')->with('default')->andReturn($redisProxy);

        $redis = new RedisStore(
            $redisFactory,
            'prefix:',
            'default'
        );

        $lock = $redis->lock('mylock', 10);

        $this->assertInstanceOf(RedisLock::class, $lock);
    }

    /**
     * @test
     */
    public function testLockWithOwner(): void
    {
        $redisProxy = m::mock(RedisProxy::class);
        $redisFactory = m::mock(Redis::class);
        $redisFactory->shouldReceive('connection')->with('default')->andReturn($redisProxy);

        $redis = new RedisStore(
            $redisFactory,
            'prefix:',
            'default'
        );

        $lock = $redis->lock('mylock', 10, 'custom-owner');

        $this->assertInstanceOf(RedisLock::class, $lock);
    }

    /**
     * @test
     */
    public function testRestoreLockReturnsRedisLockInstance(): void
    {
        $redisProxy = m::mock(RedisProxy::class);
        $redisFactory = m::mock(Redis::class);
        $redisFactory->shouldReceive('connection')->with('default')->andReturn($redisProxy);

        $redis = new RedisStore(
            $redisFactory,
            'prefix:',
            'default'
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
        $redisProxy = m::mock(RedisProxy::class);
        $lockProxy = m::mock(RedisProxy::class);
        $redisFactory = m::mock(Redis::class);
        $redisFactory->shouldReceive('connection')->with('default')->andReturn($redisProxy);
        $redisFactory->shouldReceive('connection')->with('locks')->andReturn($lockProxy);

        $redis = new RedisStore(
            $redisFactory,
            'prefix:',
            'default'
        );

        $redis->setLockConnection('locks');
        $lock = $redis->lock('mylock', 10);

        $this->assertInstanceOf(RedisLock::class, $lock);
    }

    /**
     * @test
     */
    public function testGetRedisReturnsRedis(): void
    {
        $redisFactory = m::mock(Redis::class);

        $redis = new RedisStore(
            $redisFactory,
            'prefix:',
            'default'
        );

        $this->assertSame($redisFactory, $redis->getRedis());
    }

    /**
     * @test
     */
    public function testConnectionReturnsRedisProxy(): void
    {
        $redisProxy = m::mock(RedisProxy::class);
        $redisFactory = m::mock(Redis::class);
        $redisFactory->shouldReceive('connection')->with('default')->andReturn($redisProxy);

        $redis = new RedisStore(
            $redisFactory,
            'prefix:',
            'default'
        );

        $this->assertSame($redisProxy, $redis->connection());
    }
}
