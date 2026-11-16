<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis;

use __PHP_Incomplete_Class;
use Hypervel\Cache\Redis\AllTaggedCache;
use Hypervel\Cache\Redis\AnyTaggedCache;
use Hypervel\Cache\RedisLock;
use Hypervel\Cache\RedisStore;
use Hypervel\Cache\SerializableClassPolicy;
use Hypervel\Cache\TagMode;
use Hypervel\Contracts\Redis\Factory as Redis;
use Hypervel\Redis\RedisProxy;
use Hypervel\Support\ClassInvoker;
use InvalidArgumentException;
use JMac\Testing\Double;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;
use stdClass;

class RedisStoreTest extends RedisCacheTestCase
{
    public function testSerializableClassesControlRedisValues(): void
    {
        $denyingConnection = $this->mockConnection();
        $denyingConnection->client()->expects('get')
            ->with('prefix:object')
            ->returns(serialize(new stdClass));
        $allowingConnection = $this->mockConnection();
        $allowingConnection->client()->expects('get')
            ->with('prefix:object')
            ->returns(serialize(new stdClass));

        $denyingStore = new RedisStore(
            $this->createRedisFactory($denyingConnection),
            'prefix:',
            'default',
            false,
        );
        $allowingStore = new RedisStore(
            $this->createRedisFactory($allowingConnection),
            'prefix:',
            'default',
            serializableClasses: [stdClass::class],
        );

        $this->assertInstanceOf(__PHP_Incomplete_Class::class, $denyingStore->get('object'));
        $this->assertInstanceOf(stdClass::class, $allowingStore->get('object'));
    }

    public function testSerializableClassPolicyControlsRedisValues(): void
    {
        $connection = $this->mockConnection();
        $connection->client()->expects('get')
            ->with('prefix:object')
            ->returns(serialize(new stdClass));
        $store = new RedisStore(
            $this->createRedisFactory($connection),
            'prefix:',
            'default',
            serializableClassPolicy: new SerializableClassPolicy(static fn (): false => false),
        );

        $this->assertInstanceOf(__PHP_Incomplete_Class::class, $store->get('object'));
    }

    public function testCounterFailuresAreReturnedWithoutCoercion(): void
    {
        $connection = $this->mockConnection();
        $connection->client()->expects('incrby')->with('prefix:increment', 2)->returns(false);
        $connection->client()->expects('decrby')->with('prefix:decrement', 3)->returns(false);
        $redis = $this->createStore($connection);

        $this->assertFalse($redis->increment('increment', 2));
        $this->assertFalse($redis->decrement('decrement', 3));
    }

    public function testSetConnectionClearsCachedInstances(): void
    {
        $connection1 = $this->mockConnection();
        $connection1->client()->expects('get')->with('prefix:foo')->returns(serialize('value1'));

        $connection2 = $this->mockConnection();
        $connection2->client()->expects('get')->with('prefix:foo')->returns(serialize('value2'));

        $redisProxy1 = Double::for(RedisProxy::class);
        $redisProxy1->expects('withConnection')
            ->resolves(fn (callable $callback) => $callback($connection1->shouldTransform(false)));

        $redisProxy2 = Double::for(RedisProxy::class);
        $redisProxy2->expects('withConnection')
            ->resolves(fn (callable $callback) => $callback($connection2->shouldTransform(false)));

        $redisFactory = Double::for(Redis::class)->strict();
        $redisFactory->expects('connection')->with('conn1')->returns($redisProxy1);
        $redisFactory->expects('connection')->with('conn2')->returns($redisProxy2);

        $redis = new RedisStore($redisFactory, 'prefix:', 'conn1');

        $this->assertSame('value1', $redis->get('foo'));

        $redis->setConnection('conn2');

        $this->assertSame('value2', $redis->get('foo'));
    }

    public function testSetPrefixClearsCachedOperations(): void
    {
        $connection = $this->mockConnection();
        $connection->client()->expects('get')->with('prefix:foo')->returns(serialize('old'));
        $connection->client()->expects('get')->with('newprefix:foo')->returns(serialize('new'));

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

    public function testTagsReturnsAllTaggedCache(): void
    {
        $connection = $this->mockConnection();
        $redis = $this->createStore($connection);

        $tagged = $redis->tags(['users', 'posts']);

        $this->assertInstanceOf(AllTaggedCache::class, $tagged);
    }

    public function testTagsWithSingleTagAsString(): void
    {
        $connection = $this->mockConnection();
        $redis = $this->createStore($connection);

        $tagged = $redis->tags('users');

        $this->assertInstanceOf(AllTaggedCache::class, $tagged);
    }

    public function testTagsWithVariadicArguments(): void
    {
        $connection = $this->mockConnection();
        $redis = $this->createStore($connection);

        $tagged = $redis->tags('users', 'posts', 'comments');

        $this->assertInstanceOf(AllTaggedCache::class, $tagged);
    }

    public function testDefaultTagModeIsAll(): void
    {
        $connection = $this->mockConnection();
        $redis = $this->createStore($connection);

        $this->assertSame(TagMode::All, $redis->getTagMode());
    }

    public function testSetTagModeReturnsStoreInstance(): void
    {
        $connection = $this->mockConnection();
        $redis = $this->createStore($connection);

        $result = $redis->setTagMode('any');

        $this->assertSame($redis, $result);
        $this->assertSame(TagMode::Any, $redis->getTagMode());
    }

    public function testTagsReturnsAnyTaggedCacheWhenInAnyMode(): void
    {
        $connection = $this->mockConnection();
        $redis = $this->createStore($connection);
        $redis->setTagMode('any');

        $tagged = $redis->tags(['users', 'posts']);

        $this->assertInstanceOf(AnyTaggedCache::class, $tagged);
    }

    public function testTagsReturnsAllTaggedCacheWhenInAllMode(): void
    {
        $connection = $this->mockConnection();
        $redis = $this->createStore($connection);
        $redis->setTagMode('all');

        $tagged = $redis->tags(['users', 'posts']);

        $this->assertInstanceOf(AllTaggedCache::class, $tagged);
    }

    public function testSetTagModeRejectsInvalidMode(): void
    {
        $connection = $this->mockConnection();
        $redis = $this->createStore($connection);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs(
            'Invalid cache tag mode [invalid]. Supported modes are [any, all].'
        );

        $redis->setTagMode('invalid');
    }

    public function testTouchUsesPlainExpireInAllMode(): void
    {
        $connection = $this->mockConnection();
        $connection->client()->expects('expire')
            ->with('prefix:key', 60)
            ->returns(true);

        $redis = $this->createStore($connection);

        $this->assertTrue($redis->touch('key', 60));
    }

    public function testTouchUsesAnyTagMetadataOperationInAnyMode(): void
    {
        $connection = $this->mockConnection();
        $connection->expects('evalWithShaCache')
            ->returns(true);

        $redis = $this->createStore($connection);
        $redis->setTagMode('any');

        $this->assertTrue($redis->touch('key', 60));
    }

    public function testForgetUsesPlainDeleteInAllMode(): void
    {
        $connection = $this->mockConnection();
        $connection->client()->expects('del')
            ->with('prefix:key')
            ->returns(1);

        $redis = $this->createStore($connection);

        $this->assertTrue($redis->forget('key'));
    }

    public function testForgetUsesAnyTagMetadataOperationInAnyMode(): void
    {
        $connection = $this->mockConnection();
        $connection->expects('evalWithShaCache')
            ->returns(1);

        $redis = $this->createStore($connection);
        $redis->setTagMode('any');

        $this->assertTrue($redis->forget('key'));
    }

    public function testLockReturnsRedisLockInstance(): void
    {
        $redisProxy = Double::for(RedisProxy::class);
        $redisFactory = Double::for(Redis::class)->strict();
        $redisFactory->allows('connection')->with('default')->returns($redisProxy);

        $redis = new RedisStore(
            $redisFactory,
            'prefix:',
            'default'
        );

        $lock = $redis->lock('mylock', 10);

        $this->assertInstanceOf(RedisLock::class, $lock);
    }

    public function testLockWithOwner(): void
    {
        $redisProxy = Double::for(RedisProxy::class);
        $redisFactory = Double::for(Redis::class)->strict();
        $redisFactory->allows('connection')->with('default')->returns($redisProxy);

        $redis = new RedisStore(
            $redisFactory,
            'prefix:',
            'default'
        );

        $lock = $redis->lock('mylock', 10, 'custom-owner');

        $this->assertInstanceOf(RedisLock::class, $lock);
    }

    public function testRestoreLockReturnsRedisLockInstance(): void
    {
        $redisProxy = Double::for(RedisProxy::class);
        $redisFactory = Double::for(Redis::class)->strict();
        $redisFactory->allows('connection')->with('default')->returns($redisProxy);

        $redis = new RedisStore(
            $redisFactory,
            'prefix:',
            'default'
        );

        $lock = $redis->restoreLock('mylock', 'owner-123');

        $this->assertInstanceOf(RedisLock::class, $lock);
    }

    public function testSetLockConnectionReturnsSelf(): void
    {
        $connection = $this->mockConnection();
        $redis = $this->createStore($connection);

        $result = $redis->setLockConnection('locks');

        $this->assertSame($redis, $result);
    }

    public function testLockUsesLockConnectionWhenSet(): void
    {
        $redisProxy = Double::for(RedisProxy::class);
        $lockProxy = Double::for(RedisProxy::class);
        $redisFactory = Double::for(Redis::class)->strict();
        $redisFactory->allows('connection')->with('default')->returns($redisProxy);
        $redisFactory->allows('connection')->with('locks')->returns($lockProxy);

        $redis = new RedisStore(
            $redisFactory,
            'prefix:',
            'default'
        );

        $redis->setLockConnection('locks');
        $lock = $redis->lock('mylock', 10);

        $this->assertInstanceOf(RedisLock::class, $lock);
    }

    public function testGetRedisReturnsRedis(): void
    {
        $redisFactory = Double::for(Redis::class)->strict();

        $redis = new RedisStore(
            $redisFactory,
            'prefix:',
            'default'
        );

        $this->assertSame($redisFactory, $redis->getRedis());
    }

    public function testConnectionReturnsRedisProxy(): void
    {
        $redisProxy = Double::for(RedisProxy::class);
        $redisFactory = Double::for(Redis::class)->strict();
        $redisFactory->allows('connection')->with('default')->returns($redisProxy);

        $redis = new RedisStore(
            $redisFactory,
            'prefix:',
            'default'
        );

        $this->assertSame($redisProxy, $redis->connection());
    }
}
