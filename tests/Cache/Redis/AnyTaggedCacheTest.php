<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis;

use __PHP_Incomplete_Class;
use BadMethodCallException;
use Generator;
use Hypervel\Cache\Events\CacheHit;
use Hypervel\Cache\Events\CacheMissed;
use Hypervel\Cache\Events\ForgettingKey;
use Hypervel\Cache\Events\KeyForgotten;
use Hypervel\Cache\Events\KeyWriteFailed;
use Hypervel\Cache\Events\KeyWritten;
use Hypervel\Cache\Events\RetrievingKey;
use Hypervel\Cache\Events\WritingKey;
use Hypervel\Cache\NullSentinel;
use Hypervel\Cache\Redis\AnyTaggedCache;
use Hypervel\Cache\Redis\AnyTagSet;
use Hypervel\Cache\Redis\Operations\AnyTag\Put;
use Hypervel\Cache\Redis\Operations\AnyTagOperations;
use Hypervel\Cache\RedisStore;
use Hypervel\Cache\Repository;
use Hypervel\Cache\TaggedCache;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Redis\Exceptions\LuaScriptException;
use Mockery as m;
use RuntimeException;
use stdClass;

/**
 * Tests for AnyTaggedCache behavior.
 *
 * These tests verify the high-level API behavior of union-mode tagged cache operations.
 * For detailed operation tests, see tests/Cache/Redis/Operations/AnyTag/.
 */
class AnyTaggedCacheTest extends RedisCacheTestCase
{
    /**
     * @test
     */
    public function testIsInstanceOfTaggedCache(): void
    {
        $connection = $this->mockConnection();
        $store = $this->createStore($connection);
        $cache = $store->setTagMode('any')->tags(['users', 'posts']);

        $this->assertInstanceOf(TaggedCache::class, $cache);
        $this->assertInstanceOf(AnyTaggedCache::class, $cache);
    }

    /**
     * @test
     */
    public function testGetThrowsBadMethodCallException(): void
    {
        $connection = $this->mockConnection();
        $store = $this->createStore($connection);
        $cache = $store->setTagMode('any')->tags(['users', 'posts']);

        $this->expectException(BadMethodCallException::class);
        $this->expectExceptionMessage('Cannot get items via tags in any mode');

        $cache->get('key');
    }

    /**
     * @test
     */
    public function testManyThrowsBadMethodCallException(): void
    {
        $connection = $this->mockConnection();
        $store = $this->createStore($connection);
        $cache = $store->setTagMode('any')->tags(['users', 'posts']);

        $this->expectException(BadMethodCallException::class);
        $this->expectExceptionMessage('Cannot get items via tags in any mode');

        $cache->many(['key1', 'key2']);
    }

    /**
     * @test
     */
    public function testGetMultipleThrowsBadMethodCallException(): void
    {
        $connection = $this->mockConnection();
        $store = $this->createStore($connection);
        $cache = $store->setTagMode('any')->tags(['users', 'posts']);

        $this->expectException(BadMethodCallException::class);
        $this->expectExceptionMessage('Cannot get items via tags in any mode');

        $cache->getMultiple(['key1', 'key2']);
    }

    /**
     * @test
     */
    public function testHasThrowsBadMethodCallException(): void
    {
        $connection = $this->mockConnection();
        $store = $this->createStore($connection);
        $cache = $store->setTagMode('any')->tags(['users', 'posts']);

        $this->expectException(BadMethodCallException::class);
        $this->expectExceptionMessage('Cannot check existence via tags in any mode');

        $cache->has('key');
    }

    /**
     * @test
     */
    public function testPullThrowsBadMethodCallException(): void
    {
        $connection = $this->mockConnection();
        $store = $this->createStore($connection);
        $cache = $store->setTagMode('any')->tags(['users', 'posts']);

        $this->expectException(BadMethodCallException::class);
        $this->expectExceptionMessage('Cannot pull items via tags in any mode');

        $cache->pull('key');
    }

    /**
     * @test
     */
    public function testForgetThrowsBadMethodCallException(): void
    {
        $connection = $this->mockConnection();
        $store = $this->createStore($connection);
        $cache = $store->setTagMode('any')->tags(['users', 'posts']);

        $this->expectException(BadMethodCallException::class);
        $this->expectExceptionMessage('Cannot forget items via tags in any mode');

        $cache->forget('key');
    }

    /**
     * @test
     */
    public function testTouchThrowsBadMethodCallException(): void
    {
        $connection = $this->mockConnection();
        $store = $this->createStore($connection);
        $cache = $store->setTagMode('any')->tags(['users', 'posts']);

        $this->expectException(BadMethodCallException::class);
        $this->expectExceptionMessage('Cannot touch items via tags in any mode');

        $cache->touch('key', 60);
    }

    /**
     * @test
     */
    public function testDeleteThrowsBadMethodCallException(): void
    {
        $connection = $this->mockConnection();
        $store = $this->createStore($connection);
        $cache = $store->setTagMode('any')->tags(['users', 'posts']);

        $this->expectException(BadMethodCallException::class);
        $this->expectExceptionMessage('Cannot forget items via tags in any mode');

        $cache->delete('key');
    }

    /**
     * @test
     */
    public function testDeleteMultipleThrowsBadMethodCallException(): void
    {
        $connection = $this->mockConnection();
        $store = $this->createStore($connection);
        $cache = $store->setTagMode('any')->tags(['users', 'posts']);

        $this->expectException(BadMethodCallException::class);
        $this->expectExceptionMessage('Cannot forget items via tags in any mode');

        $cache->deleteMultiple(['key1', 'key2']);
    }

    /**
     * @test
     */
    public function testArrayAccessReadOperationsThrowBadMethodCallException(): void
    {
        $connection = $this->mockConnection();
        $store = $this->createStore($connection);
        $cache = $store->setTagMode('any')->tags(['users', 'posts']);

        try {
            $cache['key'];
            $this->fail('ArrayAccess get should throw.');
        } catch (BadMethodCallException $e) {
            $this->assertStringContainsString('Cannot get items via tags in any mode', $e->getMessage());
        }

        try {
            isset($cache['key']);
            $this->fail('ArrayAccess exists should throw.');
        } catch (BadMethodCallException $e) {
            $this->assertStringContainsString('Cannot check existence via tags in any mode', $e->getMessage());
        }
    }

    /**
     * @test
     */
    public function testArrayAccessUnsetThrowsBadMethodCallException(): void
    {
        $connection = $this->mockConnection();
        $store = $this->createStore($connection);
        $cache = $store->setTagMode('any')->tags(['users', 'posts']);

        $this->expectException(BadMethodCallException::class);
        $this->expectExceptionMessage('Cannot forget items via tags in any mode');

        unset($cache['key']);
    }

    /**
     * @test
     */
    public function testPutStoresValueWithTags(): void
    {
        $connection = $this->mockConnection();

        // Union mode uses Lua script via evalWithShaCache
        $connection->shouldReceive('evalWithShaCache')
            ->once()
            ->andReturn(true);

        $store = $this->createStore($connection);
        $result = $store->setTagMode('any')->tags(['users', 'posts'])->put('mykey', 'myvalue', 60);

        $this->assertTrue($result);
    }

    /**
     * @test
     */
    public function testPutWithNullTtlCallsForever(): void
    {
        $connection = $this->mockConnection();

        // Forever operation uses Lua script via evalWithShaCache
        $connection->shouldReceive('evalWithShaCache')
            ->once()
            ->andReturn(true);

        $store = $this->createStore($connection);
        $result = $store->setTagMode('any')->tags(['users', 'posts'])->put('mykey', 'myvalue', null);

        $this->assertTrue($result);
    }

    /**
     * @test
     */
    public function testPutWithZeroTtlDeletesPlainKey(): void
    {
        $connection = $this->mockConnection();
        $connection->shouldReceive('evalWithShaCache')
            ->once()
            ->andReturn(1);

        $captured = [];
        $events = m::mock(Dispatcher::class);
        $events->shouldReceive('hasListeners')->withAnyArgs()->andReturnTrue();
        $events->shouldReceive('dispatch')
            ->andReturnUsing(function (object $event) use (&$captured): void {
                $captured[] = $event;
            });

        $store = $this->createStore($connection);
        $store->setTagMode('any');
        $cache = (new Repository($store, ['store' => 'redis']))->tags(['users', 'posts']);
        $cache->setEventDispatcher($events);

        $this->assertTrue($cache->put('mykey', 'myvalue', 0));
        $this->assertSame([ForgettingKey::class, KeyForgotten::class], array_map(get_class(...), $captured));

        foreach ($captured as $event) {
            $this->assertSame('redis', $event->storeName);
            $this->assertSame('mykey', $event->key);
            $this->assertSame(['users', 'posts'], $event->tags);
        }
    }

    /**
     * @test
     */
    public function testPutWithArrayCallsPutMany(): void
    {
        $connection = $this->mockConnection();

        // PutMany uses pipeline with Lua operations
        $connection->shouldReceive('pipeline')->andReturn($connection);
        $connection->shouldReceive('smembers')->andReturn($connection);
        $connection->shouldReceive('exec')->andReturn([[], []]);
        $connection->shouldReceive('setex')->andReturn($connection);
        $connection->shouldReceive('del')->andReturn($connection);
        $connection->shouldReceive('sadd')->andReturn($connection);
        $connection->shouldReceive('expire')->andReturn($connection);
        $connection->shouldReceive('hSet')->andReturn($connection);
        $connection->shouldReceive('hexpire')->andReturn($connection);
        $connection->shouldReceive('zadd')->andReturn($connection);

        $store = $this->createStore($connection);
        $result = $store->setTagMode('any')->tags(['users'])->put(['key1' => 'value1', 'key2' => 'value2'], 60);

        $this->assertTrue($result);
    }

    /**
     * @test
     */
    public function testPutManyStoresMultipleValues(): void
    {
        $connection = $this->mockConnection();

        // PutMany uses pipeline
        $connection->shouldReceive('pipeline')->andReturn($connection);
        $connection->shouldReceive('smembers')->andReturn($connection);
        $connection->shouldReceive('exec')->andReturn([[], []]);
        $connection->shouldReceive('setex')->andReturn($connection);
        $connection->shouldReceive('del')->andReturn($connection);
        $connection->shouldReceive('sadd')->andReturn($connection);
        $connection->shouldReceive('expire')->andReturn($connection);
        $connection->shouldReceive('hSet')->andReturn($connection);
        $connection->shouldReceive('hexpire')->andReturn($connection);
        $connection->shouldReceive('zadd')->andReturn($connection);

        $store = $this->createStore($connection);
        $result = $store->setTagMode('any')->tags(['users'])->putMany(['key1' => 'value1', 'key2' => 'value2'], 120);

        $this->assertTrue($result);
    }

    /**
     * @test
     */
    public function testPutManyWithNullTtlCallsForeverForEach(): void
    {
        $connection = $this->mockConnection();

        // Forever for each key - called twice for 2 keys
        $connection->shouldReceive('evalWithShaCache')
            ->twice()
            ->andReturn(true);

        $store = $this->createStore($connection);
        $result = $store->setTagMode('any')->tags(['users'])->putMany(['key1' => 'value1', 'key2' => 'value2'], null);

        $this->assertTrue($result);
    }

    /**
     * @test
     */
    public function testPutManyWithZeroTtlDeletesPlainKeys(): void
    {
        $connection = $this->mockConnection();
        $connection->shouldReceive('evalWithShaCache')
            ->once()
            ->andReturn(1);

        $store = $this->createStore($connection);
        $cache = $store->setTagMode('any')->tags(['users']);

        $result = $cache->putMany(['key1' => 'value1'], 0);

        $this->assertTrue($result);
    }

    /**
     * @test
     */
    public function testSetMultipleWritesThroughTaggedPath(): void
    {
        $connection = $this->mockConnection();

        $connection->shouldReceive('pipeline')->andReturn($connection);
        $connection->shouldReceive('smembers')->andReturn($connection);
        $connection->shouldReceive('exec')->andReturn([[], []]);
        $connection->shouldReceive('setex')->andReturn($connection);
        $connection->shouldReceive('del')->andReturn($connection);
        $connection->shouldReceive('sadd')->andReturn($connection);
        $connection->shouldReceive('expire')->andReturn($connection);
        $connection->shouldReceive('hSet')->andReturn($connection);
        $connection->shouldReceive('hexpire')->andReturn($connection);
        $connection->shouldReceive('zadd')->andReturn($connection);

        $store = $this->createStore($connection);
        $cache = $store->setTagMode('any')->tags(['users']);

        $this->assertTrue($cache->setMultiple(['key1' => 'value1', 'key2' => 'value2'], 60));
    }

    /**
     * @test
     */
    public function testArrayAccessSetWritesThroughTaggedPath(): void
    {
        $connection = $this->mockConnection();
        $connection->shouldReceive('evalWithShaCache')
            ->once()
            ->andReturn(true);

        $store = $this->createStore($connection);
        $cache = $store->setTagMode('any')->tags(['users']);

        $cache['mykey'] = 'myvalue';

        $this->assertTrue(true);
    }

    /**
     * @test
     */
    public function testAddStoresValueIfNotExists(): void
    {
        $connection = $this->mockConnection();

        // Add uses Lua script with SET NX via evalWithShaCache
        $connection->shouldReceive('evalWithShaCache')
            ->once()
            ->andReturn(true);

        $events = m::mock(Dispatcher::class);
        $events->shouldNotReceive('hasListeners');
        $events->shouldNotReceive('dispatch');

        $store = $this->createStore($connection);
        $store->setTagMode('any');
        $cache = (new Repository($store, ['store' => 'redis']))->tags(['users']);
        $cache->setEventDispatcher($events);

        $this->assertTrue($cache->add('mykey', 'myvalue', 60));
    }

    /**
     * @test
     */
    public function testAddWithNullTtlStoresPermanently(): void
    {
        $connection = $this->mockConnection();

        $connection->shouldReceive('evalWithShaCache')
            ->once()
            ->withArgs(function ($script, $keys, $args): bool {
                $this->assertSame(0, $args[1]);

                return true;
            })
            ->andReturn(true);

        $store = $this->createStore($connection);
        $result = $store->setTagMode('any')->tags(['users'])->add('mykey', 'myvalue', null);

        $this->assertTrue($result);
    }

    /**
     * @test
     */
    public function testAddWithZeroTtlReturnsFalse(): void
    {
        $connection = $this->mockConnection();
        $store = $this->createStore($connection);
        $cache = $store->setTagMode('any')->tags(['users']);

        $result = $cache->add('mykey', 'myvalue', 0);

        $this->assertFalse($result);
    }

    /**
     * @test
     */
    public function testForeverStoresValueIndefinitely(): void
    {
        $connection = $this->mockConnection();

        // Forever uses Lua script without expiration via evalWithShaCache
        $connection->shouldReceive('evalWithShaCache')
            ->once()
            ->andReturn(true);

        $store = $this->createStore($connection);
        $result = $store->setTagMode('any')->tags(['users'])->forever('mykey', 'myvalue');

        $this->assertTrue($result);
    }

    /**
     * @test
     */
    public function testIncrementReturnsNewValue(): void
    {
        $connection = $this->mockConnection();

        // Increment uses Lua script with INCRBY via evalWithShaCache
        $connection->shouldReceive('evalWithShaCache')
            ->once()
            ->andReturn(5);

        $store = $this->createStore($connection);
        $result = $store->setTagMode('any')->tags(['users'])->increment('counter');

        $this->assertSame(5, $result);
    }

    /**
     * @test
     */
    public function testIncrementWithCustomValue(): void
    {
        $connection = $this->mockConnection();

        $connection->shouldReceive('evalWithShaCache')
            ->once()
            ->andReturn(15);

        $store = $this->createStore($connection);
        $result = $store->setTagMode('any')->tags(['users'])->increment('counter', 10);

        $this->assertSame(15, $result);
    }

    /**
     * @test
     */
    public function testDecrementReturnsNewValue(): void
    {
        $connection = $this->mockConnection();

        // Decrement uses Lua script with DECRBY via evalWithShaCache
        $connection->shouldReceive('evalWithShaCache')
            ->once()
            ->andReturn(3);

        $store = $this->createStore($connection);
        $result = $store->setTagMode('any')->tags(['users'])->decrement('counter');

        $this->assertSame(3, $result);
    }

    /**
     * @test
     */
    public function testDecrementWithCustomValue(): void
    {
        $connection = $this->mockConnection();

        $connection->shouldReceive('evalWithShaCache')
            ->once()
            ->andReturn(0);

        $store = $this->createStore($connection);
        $result = $store->setTagMode('any')->tags(['users'])->decrement('counter', 5);

        $this->assertSame(0, $result);
    }

    /**
     * @test
     */
    public function testFlushDeletesAllTaggedItems(): void
    {
        $connection = $this->mockConnection();

        // GetTaggedKeys uses hlen to check size
        // When small (< threshold), it uses hkeys directly instead of scan
        $connection->shouldReceive('hlen')
            ->andReturn(2);
        $connection->shouldReceive('hkeys')
            ->once()
            ->andReturn(['key1', 'key2']);

        // After getting keys, Flush uses pipeline for delete operations
        $connection->shouldReceive('pipeline')->andReturn($connection);
        $connection->shouldReceive('del')->andReturn($connection);
        $connection->shouldReceive('unlink')->andReturn($connection);
        $connection->shouldReceive('zrem')->andReturn($connection);
        $connection->shouldReceive('exec')->andReturn([2, 1]);

        $store = $this->createStore($connection);
        $result = $store->setTagMode('any')->tags(['users'])->flush();

        $this->assertTrue($result);
    }

    /**
     * @test
     */
    public function testClearFlushesTaggedItems(): void
    {
        $connection = $this->mockConnection();

        $connection->shouldReceive('hlen')
            ->andReturn(2);
        $connection->shouldReceive('hkeys')
            ->once()
            ->andReturn(['key1', 'key2']);
        $connection->shouldReceive('pipeline')->andReturn($connection);
        $connection->shouldReceive('del')->andReturn($connection);
        $connection->shouldReceive('unlink')->andReturn($connection);
        $connection->shouldReceive('zrem')->andReturn($connection);
        $connection->shouldReceive('exec')->andReturn([2, 1]);

        $store = $this->createStore($connection);
        $result = $store->setTagMode('any')->tags(['users'])->clear();

        $this->assertTrue($result);
    }

    /**
     * @test
     */
    public function testRememberRetrievesExistingValueFromStore(): void
    {
        $connection = $this->mockConnection();

        $connection->shouldReceive('get')
            ->once()
            ->with('prefix:mykey')
            ->andReturn(serialize('cached_value'));

        $store = $this->createStore($connection);
        $result = $store->setTagMode('any')->tags(['users'])->remember('mykey', 60, fn () => 'new_value');

        $this->assertSame('cached_value', $result);
    }

    public function testRememberHandlesIncompleteClassBeforeDispatchingHitEvent(): void
    {
        $store = m::mock(RedisStore::class);
        $store->shouldReceive('get')->once()->with('mykey')->andReturn(
            unserialize(serialize(new stdClass), ['allowed_classes' => false])
        );

        $sequence = [];

        Repository::handleUnserializableClassUsing(function (string $key, ?string $class) use (&$sequence): void {
            $sequence[] = ['handler', $key, $class];
        });

        $events = m::mock(Dispatcher::class);
        $events->shouldReceive('hasListeners')->withAnyArgs()->andReturnTrue();
        $events->shouldReceive('dispatch')
            ->andReturnUsing(function (object $event) use (&$sequence): void {
                $sequence[] = $event::class;
            });

        $cache = new AnyTaggedCache($store, new AnyTagSet($store, ['users']));
        $cache->setEventDispatcher($events);

        $result = $cache->remember('mykey', 60, function (): never {
            $this->fail('The cache callback should not be called.');
        });

        $this->assertInstanceOf(__PHP_Incomplete_Class::class, $result);
        $this->assertSame([
            RetrievingKey::class,
            ['handler', 'mykey', 'stdClass'],
            CacheHit::class,
        ], $sequence);
    }

    /**
     * @test
     */
    public function testRememberCallsCallbackAndStoresValueWhenMiss(): void
    {
        $connection = $this->mockConnection();

        $connection->shouldReceive('get')
            ->once()
            ->with('prefix:mykey')
            ->andReturnNull();

        // Should store the value with tags via evalWithShaCache
        $connection->shouldReceive('evalWithShaCache')
            ->once()
            ->andReturn(true);

        $callCount = 0;
        $store = $this->createStore($connection);
        $result = $store->setTagMode('any')->tags(['users'])->remember('mykey', 60, function () use (&$callCount) {
            ++$callCount;

            return 'computed_value';
        });

        $this->assertSame('computed_value', $result);
        $this->assertSame(1, $callCount);
    }

    public function testRememberNormalizesEnumKeyForRedisAndEvents(): void
    {
        $connection = $this->mockConnection();

        $connection->shouldReceive('get')
            ->once()
            ->with('prefix:0')
            ->andReturnNull();

        $connection->shouldReceive('evalWithShaCache')
            ->once()
            ->withArgs(function (string $script, array $keys, array $args): bool {
                $this->assertSame('prefix:0', $keys[0]);
                $this->assertSame('0', $args[5]);

                return true;
            })
            ->andReturn(true);

        $captured = [];
        $events = m::mock(Dispatcher::class);
        $events->shouldReceive('hasListeners')->withAnyArgs()->andReturn(true);
        $events->shouldReceive('dispatch')
            ->andReturnUsing(function (object $event) use (&$captured): void {
                $captured[] = $event;
            });

        $store = $this->createStore($connection);
        $store->setTagMode('any');
        $tagged = (new Repository($store, ['store' => 'redis']))->tags(['users']);
        $tagged->setEventDispatcher($events);

        $result = $tagged->remember(AnyTaggedCacheTestKey::Profile, 60, fn () => 'computed_value');

        $this->assertSame('computed_value', $result);

        $this->assertSame(
            [RetrievingKey::class, CacheMissed::class, WritingKey::class, KeyWritten::class],
            array_map(get_class(...), $captured)
        );

        foreach ($captured as $event) {
            $this->assertSame('redis', $event->storeName);
            $this->assertSame('0', $event->key);
            $this->assertSame(['users'], $event->tags);
        }
    }

    public function testPutDispatchesTheRepositoryFailureEvent(): void
    {
        $put = m::mock(Put::class);
        $put->shouldReceive('execute')
            ->once()
            ->with('mykey', 'myvalue', 60, ['users'])
            ->andReturnFalse();

        $operations = m::mock(AnyTagOperations::class);
        $operations->shouldReceive('put')->once()->andReturn($put);

        $store = m::mock(RedisStore::class);
        $store->shouldReceive('anyTagOps')->once()->andReturn($operations);

        $tags = m::mock(AnyTagSet::class);
        $tags->shouldReceive('getNames')->andReturn(['users']);

        $cache = new AnyTaggedCache($store, $tags);
        $store->shouldReceive('tags')->once()->with(['users'])->andReturn($cache);

        $captured = [];
        $events = m::mock(Dispatcher::class);
        $events->shouldReceive('hasListeners')->withAnyArgs()->andReturnTrue();
        $events->shouldReceive('dispatch')
            ->andReturnUsing(function (object $event) use (&$captured): void {
                $captured[] = $event;
            });

        $tagged = (new Repository($store, ['store' => 'redis']))->tags(['users']);
        $tagged->setEventDispatcher($events);

        $this->assertFalse($tagged->put('mykey', 'myvalue', 60));
        $this->assertSame([WritingKey::class, KeyWriteFailed::class], array_map(get_class(...), $captured));

        foreach ($captured as $event) {
            $this->assertSame('redis', $event->storeName);
            $this->assertSame(['users'], $event->tags);
        }
    }

    public function testRememberNullableStoresAndReturnsNonNullValue(): void
    {
        $connection = $this->mockConnection();

        $connection->shouldReceive('get')->once()->with('prefix:mykey')->andReturnNull();
        $connection->shouldReceive('evalWithShaCache')->once()->andReturn(true);

        $store = $this->createStore($connection);
        $result = $store->setTagMode('any')->tags(['users'])->rememberNullable('mykey', 60, fn () => 'computed');

        $this->assertSame('computed', $result);
    }

    public function testRememberNullableStoresSentinelWhenCallbackReturnsNull(): void
    {
        $connection = $this->mockConnection();

        $connection->shouldReceive('get')->once()->with('prefix:mykey')->andReturnNull();

        $connection->shouldReceive('evalWithShaCache')
            ->once()
            ->withArgs(function ($script, $keys, $args): bool {
                foreach (array_merge((array) $keys, (array) $args) as $arg) {
                    if (is_string($arg) && @unserialize($arg) === NullSentinel::VALUE) {
                        return true;
                    }
                }
                return false;
            })
            ->andReturn(true);

        $store = $this->createStore($connection);
        $result = $store->setTagMode('any')->tags(['users'])->rememberNullable('mykey', 60, fn () => null);

        $this->assertNull($result);
    }

    public function testRememberNullableReturnsNullOnSentinelHitWithoutInvokingCallback(): void
    {
        $connection = $this->mockConnection();

        $connection->shouldReceive('get')
            ->once()
            ->with('prefix:mykey')
            ->andReturn(serialize(NullSentinel::VALUE));

        $invoked = false;
        $store = $this->createStore($connection);
        $result = $store->setTagMode('any')->tags(['users'])->rememberNullable('mykey', 60, function () use (&$invoked) {
            $invoked = true;
            return 'should-not-run';
        });

        $this->assertNull($result);
        $this->assertFalse($invoked);
    }

    public function testRememberNullableFiresCacheHitWithNullPayloadOnSentinelHit(): void
    {
        $connection = $this->mockConnection();

        $connection->shouldReceive('get')
            ->once()
            ->with('prefix:mykey')
            ->andReturn(serialize(NullSentinel::VALUE));

        $captured = [];
        $events = m::mock(Dispatcher::class);
        $events->shouldReceive('hasListeners')->withAnyArgs()->andReturn(true);
        $events->shouldReceive('dispatch')
            ->andReturnUsing(function ($event) use (&$captured) {
                $captured[] = $event;
            });

        $store = $this->createStore($connection);
        $tagged = $store->setTagMode('any')->tags(['users']);
        $tagged->setEventDispatcher($events);

        $tagged->rememberNullable('mykey', 60, fn () => 'should-not-run');

        $cacheHit = array_values(array_filter($captured, fn ($e) => $e instanceof CacheHit))[0] ?? null;
        $this->assertNotNull($cacheHit);
        // Null, not the sentinel value.
        $this->assertNull($cacheHit->value);
    }

    public function testRememberNullableFiresKeyWrittenWithNullPayloadOnCacheMiss(): void
    {
        $connection = $this->mockConnection();

        $connection->shouldReceive('get')->once()->with('prefix:mykey')->andReturnNull();
        $connection->shouldReceive('evalWithShaCache')->once()->andReturn(true);

        $captured = [];
        $events = m::mock(Dispatcher::class);
        $events->shouldReceive('hasListeners')->withAnyArgs()->andReturn(true);
        $events->shouldReceive('dispatch')
            ->andReturnUsing(function ($event) use (&$captured) {
                $captured[] = $event;
            });

        $store = $this->createStore($connection);
        $tagged = $store->setTagMode('any')->tags(['users']);
        $tagged->setEventDispatcher($events);

        $tagged->rememberNullable('mykey', 60, fn () => null);

        $keyWritten = array_values(array_filter($captured, fn ($e) => $e instanceof KeyWritten))[0] ?? null;
        $this->assertNotNull($keyWritten);
        // Null, not the sentinel value.
        $this->assertNull($keyWritten->value);
    }

    /**
     * Proves tags()->remember() (plain, non-nullable) unwraps the sentinel on return
     * on an any-mode tagged cache — the sentinel never leaks through the public
     * non-nullable API.
     */
    public function testPlainRememberUnwrapsSentinelOnCachedNullHit(): void
    {
        $connection = $this->mockConnection();

        $connection->shouldReceive('get')
            ->once()
            ->with('prefix:mykey')
            ->andReturn(serialize(NullSentinel::VALUE));

        $invoked = false;
        $store = $this->createStore($connection);
        $result = $store->setTagMode('any')->tags(['users'])->remember('mykey', 60, function () use (&$invoked) {
            $invoked = true;
            return 'should-not-run';
        });

        $this->assertNull($result);
        $this->assertFalse($invoked);
    }

    /**
     * @test
     */
    public function testRememberForeverRetrievesExistingValueFromStore(): void
    {
        $connection = $this->mockConnection();

        $connection->shouldReceive('get')
            ->once()
            ->with('prefix:mykey')
            ->andReturn(serialize('cached_value'));

        $store = $this->createStore($connection);
        $result = $store->setTagMode('any')->tags(['users'])->rememberForever('mykey', fn () => 'new_value');

        $this->assertSame('cached_value', $result);
    }

    public function testRememberForeverNormalizesEnumKeyForRedisAndEvents(): void
    {
        $connection = $this->mockConnection();

        $connection->shouldReceive('get')
            ->once()
            ->with('prefix:1')
            ->andReturn(serialize('cached_settings'));

        $captured = [];
        $events = m::mock(Dispatcher::class);
        $events->shouldReceive('hasListeners')->withAnyArgs()->andReturn(true);
        $events->shouldReceive('dispatch')
            ->andReturnUsing(function (object $event) use (&$captured): void {
                $captured[] = $event;
            });

        $tagged = $this->createStore($connection)->setTagMode('any')->tags(['users']);
        $tagged->setEventDispatcher($events);

        $result = $tagged->rememberForever(AnyTaggedCacheTestKey::Settings, fn () => 'new_settings');

        $this->assertSame('cached_settings', $result);

        $cacheHit = array_values(array_filter($captured, fn (object $event) => $event instanceof CacheHit))[0] ?? null;

        $this->assertNotNull($cacheHit);
        $this->assertSame('1', $cacheHit->key);
    }

    /**
     * @test
     */
    public function testRememberForeverCallsCallbackAndStoresValueWhenMiss(): void
    {
        $connection = $this->mockConnection();

        $connection->shouldReceive('get')
            ->once()
            ->with('prefix:mykey')
            ->andReturnNull();

        // Should store the value forever with tags using evalWithShaCache
        $connection->shouldReceive('evalWithShaCache')
            ->once()
            ->andReturn(true);

        $store = $this->createStore($connection);
        $result = $store->setTagMode('any')->tags(['users'])->rememberForever('mykey', fn () => 'computed_value');

        $this->assertSame('computed_value', $result);
    }

    public function testRememberForeverNullableStoresSentinelWhenCallbackReturnsNull(): void
    {
        $connection = $this->mockConnection();

        $connection->shouldReceive('get')->once()->with('prefix:mykey')->andReturnNull();
        $connection->shouldReceive('evalWithShaCache')
            ->once()
            ->withArgs(function ($script, $keys, $args): bool {
                foreach (array_merge((array) $keys, (array) $args) as $arg) {
                    if (is_string($arg) && @unserialize($arg) === NullSentinel::VALUE) {
                        return true;
                    }
                }
                return false;
            })
            ->andReturn(true);

        $store = $this->createStore($connection);
        $result = $store->setTagMode('any')->tags(['users'])->rememberForeverNullable('mykey', fn () => null);

        $this->assertNull($result);
    }

    public function testSearNullableDelegatesToRememberForeverNullable(): void
    {
        $connection = $this->mockConnection();

        $connection->shouldReceive('get')
            ->once()
            ->with('prefix:mykey')
            ->andReturn(serialize(NullSentinel::VALUE));

        $store = $this->createStore($connection);
        $result = $store->setTagMode('any')->tags(['users'])->searNullable('mykey', fn () => 'should-not-run');

        $this->assertNull($result);
    }

    public function testPlainRememberForeverUnwrapsSentinelOnCachedNullHit(): void
    {
        $connection = $this->mockConnection();

        $connection->shouldReceive('get')
            ->once()
            ->with('prefix:mykey')
            ->andReturn(serialize(NullSentinel::VALUE));

        $invoked = false;
        $store = $this->createStore($connection);
        $result = $store->setTagMode('any')->tags(['users'])->rememberForever('mykey', function () use (&$invoked) {
            $invoked = true;
            return 'should-not-run';
        });

        $this->assertNull($result);
        $this->assertFalse($invoked);
    }

    /**
     * @test
     */
    public function testGetTagsReturnsTagSet(): void
    {
        $connection = $this->mockConnection();
        $store = $this->createStore($connection);
        $cache = $store->setTagMode('any')->tags(['users', 'posts']);

        $this->assertInstanceOf(AnyTagSet::class, $cache->getTags());
    }

    /**
     * @test
     */
    public function testItemKeyReturnsKeyUnchanged(): void
    {
        $connection = $this->mockConnection();

        // In any mode, keys are NOT namespaced by tags
        $connection->shouldReceive('get')
            ->once()
            ->with('prefix:mykey') // Should NOT have tag namespace prefix
            ->andReturn(serialize('value'));

        $store = $this->createStore($connection);
        $store->setTagMode('any')->tags(['users'])->remember('mykey', 60, fn () => 'fallback');
    }

    /**
     * @test
     */
    public function testIncrementThrowsOnLuaFailure(): void
    {
        $connection = $this->mockConnection();

        $connection->shouldReceive('evalWithShaCache')
            ->once()
            ->andThrow(new LuaScriptException('Lua script execution failed'));

        $this->expectException(LuaScriptException::class);

        $store = $this->createStore($connection);
        $store->setTagMode('any')->tags(['users'])->increment('counter');
    }

    /**
     * @test
     */
    public function testDecrementThrowsOnLuaFailure(): void
    {
        $connection = $this->mockConnection();

        $connection->shouldReceive('evalWithShaCache')
            ->once()
            ->andThrow(new LuaScriptException('Lua script execution failed'));

        $this->expectException(LuaScriptException::class);

        $store = $this->createStore($connection);
        $store->setTagMode('any')->tags(['users'])->decrement('counter');
    }

    /**
     * @test
     */
    public function testRememberPropagatesExceptionFromCallback(): void
    {
        $connection = $this->mockConnection();

        // Client returns null (cache miss) - callback will be executed
        $connection->shouldReceive('get')
            ->once()
            ->with('prefix:mykey')
            ->andReturnNull();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Callback failed');

        $store = $this->createStore($connection);
        $store->setTagMode('any')->tags(['users'])->remember('mykey', 60, function () {
            throw new RuntimeException('Callback failed');
        });
    }

    /**
     * @test
     */
    public function testRememberForeverPropagatesExceptionFromCallback(): void
    {
        $connection = $this->mockConnection();

        // Client returns null (cache miss) - callback will be executed
        $connection->shouldReceive('get')
            ->once()
            ->with('prefix:mykey')
            ->andReturnNull();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Forever callback failed');

        $store = $this->createStore($connection);
        $store->setTagMode('any')->tags(['users'])->rememberForever('mykey', function () {
            throw new RuntimeException('Forever callback failed');
        });
    }

    /**
     * @test
     */
    public function testRememberDoesNotCallCallbackWhenValueExists(): void
    {
        $connection = $this->mockConnection();

        // Client returns existing value (cache hit)
        $connection->shouldReceive('get')
            ->once()
            ->with('prefix:mykey')
            ->andReturn(serialize('cached_value'));

        $callCount = 0;
        $store = $this->createStore($connection);
        $result = $store->setTagMode('any')->tags(['users'])->remember('mykey', 60, function () use (&$callCount) {
            ++$callCount;

            return 'new_value';
        });

        $this->assertSame('cached_value', $result);
        $this->assertSame(0, $callCount, 'Callback should not be called when cache hit');
    }

    /**
     * @test
     */
    public function testItemsReturnsGenerator(): void
    {
        $connection = $this->mockConnection();

        // GetTaggedKeys uses hlen to check size first
        $connection->shouldReceive('hlen')
            ->andReturn(2);

        // When small (< threshold), it uses hkeys directly
        $connection->shouldReceive('hkeys')
            ->once()
            ->andReturn(['key1', 'key2']);

        // Get values for found keys (mget receives array)
        $connection->shouldReceive('mget')
            ->once()
            ->with(['prefix:key1', 'prefix:key2'])
            ->andReturn([serialize('value1'), serialize('value2')]);

        $store = $this->createStore($connection);
        $result = $store->setTagMode('any')->tags(['users'])->items();

        $this->assertInstanceOf(Generator::class, $result);

        // Iterate the generator to verify it works and trigger the Redis calls
        $items = iterator_to_array($result);
        $this->assertCount(2, $items);
    }

    public function testFlexibleNullableThrowsBadMethodCallException(): void
    {
        $connection = $this->mockConnection();
        $store = $this->createStore($connection);
        $cache = $store->setTagMode('any')->tags(['users']);

        $this->expectException(BadMethodCallException::class);
        $this->expectExceptionMessage('Cannot get items via tags in any mode');

        $cache->flexibleNullable('mykey', [60, 120], fn () => 'v');
    }
}

enum AnyTaggedCacheTestKey: int
{
    case Profile = 0;
    case Settings = 1;
}
