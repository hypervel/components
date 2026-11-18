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
use Hypervel\Cache\Events\WritingManyKeys;
use Hypervel\Cache\NullSentinel;
use Hypervel\Cache\Redis\AnyTaggedCache;
use Hypervel\Cache\Redis\AnyTagSet;
use Hypervel\Cache\Redis\Operations\AnyTag\Forever;
use Hypervel\Cache\Redis\Operations\AnyTag\Put;
use Hypervel\Cache\Redis\Operations\AnyTag\PutMany;
use Hypervel\Cache\Redis\Operations\AnyTagOperations;
use Hypervel\Cache\RedisStore;
use Hypervel\Cache\Repository;
use Hypervel\Cache\TaggedCache;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Redis\Exceptions\LuaScriptException;
use JMac\Testing\Double;
use JMac\Testing\Matching\Argument;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use stdClass;
use Swoole\Coroutine\CanceledException;
use Throwable;

/**
 * Tests for AnyTaggedCache behavior.
 *
 * These tests verify the high-level API behavior of union-mode tagged cache operations.
 * For detailed operation tests, see tests/Cache/Redis/Operations/AnyTag/.
 */
class AnyTaggedCacheTest extends RedisCacheTestCase
{
    public function testIsInstanceOfTaggedCache(): void
    {
        $connection = $this->mockConnection();
        $store = $this->createStore($connection);
        $cache = $store->setTagMode('any')->tags(['users', 'posts']);

        $this->assertInstanceOf(TaggedCache::class, $cache);
        $this->assertInstanceOf(AnyTaggedCache::class, $cache);
    }

    public function testGetThrowsBadMethodCallException(): void
    {
        $connection = $this->mockConnection();
        $store = $this->createStore($connection);
        $cache = $store->setTagMode('any')->tags(['users', 'posts']);

        $this->expectException(BadMethodCallException::class);
        $this->expectExceptionMessageIsOrContains('Cannot get items via tags in any mode');

        $cache->get('key');
    }

    public function testGetAuthoritativeRawThrowsBadMethodCallException(): void
    {
        $connection = $this->mockConnection();
        $store = $this->createStore($connection);
        $cache = $store->setTagMode('any')->tags(['users', 'posts']);

        $this->expectException(BadMethodCallException::class);
        $this->expectExceptionMessageIsOrContains('Cannot get items via tags in any mode');

        $cache->getAuthoritativeRaw('key');
    }

    public function testManyThrowsBadMethodCallException(): void
    {
        $connection = $this->mockConnection();
        $store = $this->createStore($connection);
        $cache = $store->setTagMode('any')->tags(['users', 'posts']);

        $this->expectException(BadMethodCallException::class);
        $this->expectExceptionMessageIsOrContains('Cannot get items via tags in any mode');

        $cache->many(['key1', 'key2']);
    }

    public function testGetMultipleThrowsBadMethodCallException(): void
    {
        $connection = $this->mockConnection();
        $store = $this->createStore($connection);
        $cache = $store->setTagMode('any')->tags(['users', 'posts']);

        $this->expectException(BadMethodCallException::class);
        $this->expectExceptionMessageIsOrContains('Cannot get items via tags in any mode');

        $cache->getMultiple(['key1', 'key2']);
    }

    public function testHasThrowsBadMethodCallException(): void
    {
        $connection = $this->mockConnection();
        $store = $this->createStore($connection);
        $cache = $store->setTagMode('any')->tags(['users', 'posts']);

        $this->expectException(BadMethodCallException::class);
        $this->expectExceptionMessageIsOrContains('Cannot check existence via tags in any mode');

        $cache->has('key');
    }

    public function testPullThrowsBadMethodCallException(): void
    {
        $connection = $this->mockConnection();
        $store = $this->createStore($connection);
        $cache = $store->setTagMode('any')->tags(['users', 'posts']);

        $this->expectException(BadMethodCallException::class);
        $this->expectExceptionMessageIsOrContains('Cannot pull items via tags in any mode');

        $cache->pull('key');
    }

    public function testForgetThrowsBadMethodCallException(): void
    {
        $connection = $this->mockConnection();
        $store = $this->createStore($connection);
        $cache = $store->setTagMode('any')->tags(['users', 'posts']);

        $this->expectException(BadMethodCallException::class);
        $this->expectExceptionMessageIsOrContains('Cannot forget items via tags in any mode');

        $cache->forget('key');
    }

    public function testTouchThrowsBadMethodCallException(): void
    {
        $connection = $this->mockConnection();
        $store = $this->createStore($connection);
        $cache = $store->setTagMode('any')->tags(['users', 'posts']);

        $this->expectException(BadMethodCallException::class);
        $this->expectExceptionMessageIsOrContains('Cannot touch items via tags in any mode');

        $cache->touch('key', 60);
    }

    public function testDeleteThrowsBadMethodCallException(): void
    {
        $connection = $this->mockConnection();
        $store = $this->createStore($connection);
        $cache = $store->setTagMode('any')->tags(['users', 'posts']);

        $this->expectException(BadMethodCallException::class);
        $this->expectExceptionMessageIsOrContains('Cannot forget items via tags in any mode');

        $cache->delete('key');
    }

    public function testDeleteMultipleThrowsBadMethodCallException(): void
    {
        $connection = $this->mockConnection();
        $store = $this->createStore($connection);
        $cache = $store->setTagMode('any')->tags(['users', 'posts']);

        $this->expectException(BadMethodCallException::class);
        $this->expectExceptionMessageIsOrContains('Cannot forget items via tags in any mode');

        $cache->deleteMultiple(['key1', 'key2']);
    }

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

    public function testArrayAccessUnsetThrowsBadMethodCallException(): void
    {
        $connection = $this->mockConnection();
        $store = $this->createStore($connection);
        $cache = $store->setTagMode('any')->tags(['users', 'posts']);

        $this->expectException(BadMethodCallException::class);
        $this->expectExceptionMessageIsOrContains('Cannot forget items via tags in any mode');

        unset($cache['key']);
    }

    public function testPutStoresValueWithTags(): void
    {
        $connection = $this->mockConnection();

        // Union mode uses Lua script via evalWithShaCache
        $connection->expects('evalWithShaCache')
            ->returns(true);

        $store = $this->createStore($connection);
        $result = $store->setTagMode('any')->tags(['users', 'posts'])->put('mykey', 'myvalue', 60);

        $this->assertTrue($result);
    }

    public function testPutWithNullTtlCallsForever(): void
    {
        $connection = $this->mockConnection();

        // Forever operation uses Lua script via evalWithShaCache
        $connection->expects('evalWithShaCache')
            ->returns(true);

        $store = $this->createStore($connection);
        $result = $store->setTagMode('any')->tags(['users', 'posts'])->put('mykey', 'myvalue', null);

        $this->assertTrue($result);
    }

    public function testPutWithZeroTtlDeletesPlainKey(): void
    {
        $connection = $this->mockConnection();
        $connection->expects('evalWithShaCache')
            ->returns(1);

        $captured = [];
        $events = Double::for(Dispatcher::class)->strict();
        $events->allows('hasListeners')->returns(true);
        $events->allows('dispatch')
            ->resolves(function (object $event) use (&$captured): void {
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

    public function testPutWithArrayCallsPutMany(): void
    {
        $connection = $this->mockConnection();

        $connection->client()->allows('pipeline')->returns($connection->client());
        $connection->client()->allows('smembers')->returns($connection->client());
        $connection->client()->allows('exec')->returns([[], []]);
        $connection->client()->allows('setex')->returns($connection->client());
        $connection->client()->allows('del')->returns($connection->client());
        $connection->client()->allows('sadd')->returns($connection->client());
        $connection->client()->allows('expire')->returns($connection->client());
        $connection->client()->allows('hsetex')->returns($connection->client());
        $connection->client()->allows('zadd')->returns($connection->client());

        $store = $this->createStore($connection);
        $result = $store->setTagMode('any')->tags(['users'])->put(['key1' => 'value1', 'key2' => 'value2'], 60);

        $this->assertTrue($result);
    }

    public function testPutManyStoresMultipleValues(): void
    {
        $connection = $this->mockConnection();

        $connection->client()->allows('pipeline')->returns($connection->client());
        $connection->client()->allows('smembers')->returns($connection->client());
        $connection->client()->allows('exec')->returns([[], []]);
        $connection->client()->allows('setex')->returns($connection->client());
        $connection->client()->allows('del')->returns($connection->client());
        $connection->client()->allows('sadd')->returns($connection->client());
        $connection->client()->allows('expire')->returns($connection->client());
        $connection->client()->allows('hsetex')->returns($connection->client());
        $connection->client()->allows('zadd')->returns($connection->client());

        $store = $this->createStore($connection);
        $result = $store->setTagMode('any')->tags(['users'])->putMany(['key1' => 'value1', 'key2' => 'value2'], 120);

        $this->assertTrue($result);
    }

    public function testPutManyWithNullTtlCallsForeverForEach(): void
    {
        $connection = $this->mockConnection();

        // Forever for each key - called twice for 2 keys
        $connection->expects('evalWithShaCache')
            ->times(2)
            ->returns(true);

        $store = $this->createStore($connection);
        $result = $store->setTagMode('any')->tags(['users'])->putMany(['key1' => 'value1', 'key2' => 'value2'], null);

        $this->assertTrue($result);
    }

    public function testPutManyWithZeroTtlDeletesPlainKeys(): void
    {
        $connection = $this->mockConnection();
        $connection->expects('evalWithShaCache')
            ->returns(1);

        $store = $this->createStore($connection);
        $cache = $store->setTagMode('any')->tags(['users']);

        $result = $cache->putMany(['key1' => 'value1'], 0);

        $this->assertTrue($result);
    }

    public function testSetMultipleWritesThroughTaggedPath(): void
    {
        $connection = $this->mockConnection();

        $connection->client()->allows('pipeline')->returns($connection->client());
        $connection->client()->allows('smembers')->returns($connection->client());
        $connection->client()->allows('exec')->returns([[], []]);
        $connection->client()->allows('setex')->returns($connection->client());
        $connection->client()->allows('del')->returns($connection->client());
        $connection->client()->allows('sadd')->returns($connection->client());
        $connection->client()->allows('expire')->returns($connection->client());
        $connection->client()->allows('hsetex')->returns($connection->client());
        $connection->client()->allows('zadd')->returns($connection->client());

        $store = $this->createStore($connection);
        $cache = $store->setTagMode('any')->tags(['users']);

        $this->assertTrue($cache->setMultiple(['key1' => 'value1', 'key2' => 'value2'], 60));
    }

    public function testArrayAccessSetWritesThroughTaggedPath(): void
    {
        $connection = $this->mockConnection();
        $connection->expects('evalWithShaCache')
            ->returns(true);

        $store = $this->createStore($connection);
        $cache = $store->setTagMode('any')->tags(['users']);

        $cache['mykey'] = 'myvalue';
    }

    public function testAddStoresValueIfNotExists(): void
    {
        $connection = $this->mockConnection();

        // Add uses Lua script with SET NX via evalWithShaCache
        $connection->expects('evalWithShaCache')
            ->returns(true);

        $events = Double::for(Dispatcher::class)->strict();
        $events->expects('hasListeners')->never();
        $events->expects('dispatch')->never();

        $store = $this->createStore($connection);
        $store->setTagMode('any');
        $cache = (new Repository($store, ['store' => 'redis']))->tags(['users']);
        $cache->setEventDispatcher($events);

        $this->assertTrue($cache->add('mykey', 'myvalue', 60));
    }

    public function testAddWithNullTtlStoresPermanently(): void
    {
        $connection = $this->mockConnection();

        $connection->expects('evalWithShaCache')
            ->with(Argument::all(function ($script, $keys, $args): bool {
                $this->assertSame(0, $args[1]);

                return true;
            }))
            ->returns(true);

        $store = $this->createStore($connection);
        $result = $store->setTagMode('any')->tags(['users'])->add('mykey', 'myvalue', null);

        $this->assertTrue($result);
    }

    public function testAddWithZeroTtlReturnsFalse(): void
    {
        $connection = $this->mockConnection();
        $store = $this->createStore($connection);
        $cache = $store->setTagMode('any')->tags(['users']);

        $result = $cache->add('mykey', 'myvalue', 0);

        $this->assertFalse($result);
    }

    public function testForeverStoresValueIndefinitely(): void
    {
        $connection = $this->mockConnection();

        // Forever uses Lua script without expiration via evalWithShaCache
        $connection->expects('evalWithShaCache')
            ->returns(true);

        $store = $this->createStore($connection);
        $result = $store->setTagMode('any')->tags(['users'])->forever('mykey', 'myvalue');

        $this->assertTrue($result);
    }

    public function testIncrementReturnsNewValue(): void
    {
        $connection = $this->mockConnection();

        // Increment uses Lua script with INCRBY via evalWithShaCache
        $connection->expects('evalWithShaCache')
            ->returns(5);

        $store = $this->createStore($connection);
        $result = $store->setTagMode('any')->tags(['users'])->increment('counter');

        $this->assertSame(5, $result);
    }

    public function testIncrementWithCustomValue(): void
    {
        $connection = $this->mockConnection();

        $connection->expects('evalWithShaCache')
            ->returns(15);

        $store = $this->createStore($connection);
        $result = $store->setTagMode('any')->tags(['users'])->increment('counter', 10);

        $this->assertSame(15, $result);
    }

    public function testDecrementReturnsNewValue(): void
    {
        $connection = $this->mockConnection();

        // Decrement uses Lua script with DECRBY via evalWithShaCache
        $connection->expects('evalWithShaCache')
            ->returns(3);

        $store = $this->createStore($connection);
        $result = $store->setTagMode('any')->tags(['users'])->decrement('counter');

        $this->assertSame(3, $result);
    }

    public function testDecrementWithCustomValue(): void
    {
        $connection = $this->mockConnection();

        $connection->expects('evalWithShaCache')
            ->returns(0);

        $store = $this->createStore($connection);
        $result = $store->setTagMode('any')->tags(['users'])->decrement('counter', 5);

        $this->assertSame(0, $result);
    }

    public function testFlushDeletesAllTaggedItems(): void
    {
        $connection = $this->mockConnection();

        // GetTaggedKeys uses hlen to check size
        // When small (< threshold), it uses hkeys directly instead of scan
        $connection->client()->expects('hlen')
            ->with('prefix:_any:tag:users:entries')
            ->returns(2);
        $connection->client()->expects('hkeys')
            ->with('prefix:_any:tag:users:entries')
            ->returns(['key1', 'key2']);

        $connection->expects('evalWithShaCache')
            ->with(Argument::type('string'), ['prefix:_any:tag:users:entries', 'prefix:key1:_any:tags', 'prefix:key2:_any:tags', 'prefix:key1', 'prefix:key2'], [2, 'key1', 'key2'])
            ->returns(1);
        $connection->expects('evalWithShaCache')
            ->with(Argument::type('string'), ['prefix:_any:tag:registry', 'prefix:_any:tag:users:entries'], ['users'])
            ->returns([1, 1]);

        $store = $this->createStore($connection);
        $result = $store->setTagMode('any')->tags(['users'])->flush();

        $this->assertTrue($result);
    }

    public function testClearFlushesTaggedItems(): void
    {
        $connection = $this->mockConnection();

        $connection->client()->expects('hlen')
            ->with('prefix:_any:tag:users:entries')
            ->returns(2);
        $connection->client()->expects('hkeys')
            ->with('prefix:_any:tag:users:entries')
            ->returns(['key1', 'key2']);
        $connection->expects('evalWithShaCache')
            ->with(Argument::type('string'), ['prefix:_any:tag:users:entries', 'prefix:key1:_any:tags', 'prefix:key2:_any:tags', 'prefix:key1', 'prefix:key2'], [2, 'key1', 'key2'])
            ->returns(1);
        $connection->expects('evalWithShaCache')
            ->with(Argument::type('string'), ['prefix:_any:tag:registry', 'prefix:_any:tag:users:entries'], ['users'])
            ->returns([1, 1]);

        $store = $this->createStore($connection);
        $result = $store->setTagMode('any')->tags(['users'])->clear();

        $this->assertTrue($result);
    }

    public function testRememberRetrievesExistingValueFromStore(): void
    {
        $connection = $this->mockConnection();

        $connection->client()->expects('get')
            ->with('prefix:mykey')
            ->returns(serialize('cached_value'));

        $store = $this->createStore($connection);
        $result = $store->setTagMode('any')->tags(['users'])->remember('mykey', 60, fn () => 'new_value');

        $this->assertSame('cached_value', $result);
    }

    public function testRememberHandlesIncompleteClassBeforeDispatchingHitEvent(): void
    {
        $store = Double::for(RedisStore::class)->strict();
        $store->expects('get')->with('mykey')->returns(
            unserialize(serialize(new stdClass), ['allowed_classes' => false])
        );

        $sequence = [];

        Repository::handleUnserializableClassUsing(function (string $key, ?string $class) use (&$sequence): void {
            $sequence[] = ['handler', $key, $class];
        });

        $events = Double::for(Dispatcher::class)->strict();
        $events->allows('hasListeners')->returns(true);
        $events->allows('dispatch')
            ->resolves(function (object $event) use (&$sequence): void {
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

    public function testRememberCallsCallbackAndStoresValueWhenMiss(): void
    {
        $connection = $this->mockConnection();

        $connection->client()->expects('get')
            ->with('prefix:mykey')
            ->returns(null);

        // Should store the value with tags via evalWithShaCache
        $connection->expects('evalWithShaCache')
            ->returns(true);

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

        $connection->client()->expects('get')
            ->with('prefix:0')
            ->returns(null);

        $connection->expects('evalWithShaCache')
            ->with(Argument::all(function (string $script, array $keys, array $args): bool {
                $this->assertSame('prefix:0', $keys[0]);
                $this->assertSame('0', $args[5]);

                return true;
            }))
            ->returns(true);

        $captured = [];
        $events = Double::for(Dispatcher::class)->strict();
        $events->allows('hasListeners')->returns(true);
        $events->allows('dispatch')
            ->resolves(function (object $event) use (&$captured): void {
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
        $put = Double::for(Put::class)->strict();
        $put->expects('execute')
            ->with('mykey', 'myvalue', 60, ['users'])
            ->returns(false);

        $operations = Double::for(AnyTagOperations::class)->strict();
        $operations->expects('put')->returns($put);

        $store = Double::for(RedisStore::class)->strict();
        $store->expects('anyTagOps')->returns($operations);

        $tags = Double::for(AnyTagSet::class)->strict();
        $tags->allows('getNames')->returns(['users']);

        $cache = new AnyTaggedCache($store, $tags);
        $store->expects('tags')->with(['users'])->returns($cache);

        $captured = [];
        $events = Double::for(Dispatcher::class)->strict();
        $events->allows('hasListeners')->returns(true);
        $events->allows('dispatch')
            ->resolves(function (object $event) use (&$captured): void {
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

    public function testAnyTaggedCacheSkipsEventDispatchSeamWithoutListenersAndEntersItWithListeners(): void
    {
        $put = Double::for(Put::class)->strict();
        $put->expects('execute')->times(2)->returns(true);
        $operations = Double::for(AnyTagOperations::class)->strict();
        $operations->expects('put')->times(2)->returns($put);
        $store = Double::for(RedisStore::class)->strict();
        $store->expects('anyTagOps')->times(2)->returns($operations);
        $tags = Double::for(AnyTagSet::class)->strict();
        $tags->allows('getNames')->returns(['users']);

        $cache = new class($store, $tags) extends AnyTaggedCache {
            public int $eventCalls = 0;

            protected function event(object $event): void
            {
                ++$this->eventCalls;

                parent::event($event);
            }
        };
        $events = Double::for(Dispatcher::class)->strict();
        $events->allows('hasListeners')->returns(false);
        $events->expects('dispatch')->never();
        $cache->setEventDispatcher($events);

        $this->assertTrue($cache->put('first', 'value', 60));
        $this->assertSame(0, $cache->eventCalls);

        $captured = [];
        $events = Double::for(Dispatcher::class)->strict();
        $events->allows('hasListeners')->returns(true);
        $events->expects('dispatch')->times(2)->resolves(function (object $event) use (&$captured): void {
            $captured[] = $event;
        });
        $cache->setEventDispatcher($events);

        $this->assertTrue($cache->put('second', 'value', 60));
        $this->assertSame(2, $cache->eventCalls);
        $this->assertSame([WritingKey::class, KeyWritten::class], array_map(get_class(...), $captured));

        foreach ($captured as $event) {
            $this->assertSame(['users'], $event->tags);
        }
    }

    public function testPutDispatchesTheRepositoryFailureEventWhenTheOperationThrows(): void
    {
        $exception = new RuntimeException('write failed');
        $put = Double::for(Put::class)->strict();
        $put->expects('execute')
            ->with('mykey', 'myvalue', 60, ['users'])
            ->throws($exception);

        $operations = Double::for(AnyTagOperations::class)->strict();
        $operations->expects('put')->returns($put);
        $captured = [];
        $tagged = $this->taggedCacheWithOperations($operations, $captured);

        try {
            $tagged->put('mykey', 'myvalue', 60);
            $this->fail('Expected the tagged cache write exception to be rethrown.');
        } catch (RuntimeException $caught) {
            $this->assertSame($exception, $caught);
        }

        $this->assertSame([WritingKey::class, KeyWriteFailed::class], array_map(get_class(...), $captured));
        $this->assertSame(['users'], $captured[1]->tags);
        $this->assertSame($exception, $captured[1]->exception);
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testPutDoesNotDispatchFailureEventWhenTheOperationIsCanceled(bool $cleanupThrows): void
    {
        $cancellation = new CanceledException('write canceled');
        if ($cleanupThrows) {
            $cancellation = new RuntimeException('Cleanup failed.', previous: $cancellation);
        }
        $put = Double::for(Put::class)->strict();
        $put->expects('execute')
            ->with('mykey', 'myvalue', 60, ['users'])
            ->throws($cancellation);

        $operations = Double::for(AnyTagOperations::class)->strict();
        $operations->expects('put')->returns($put);
        $captured = [];
        $tagged = $this->taggedCacheWithOperations($operations, $captured);

        try {
            $tagged->put('mykey', 'myvalue', 60);
            $this->fail('Expected the tagged cache write cancellation to be rethrown.');
        } catch (Throwable $exception) {
            $this->assertSame($cancellation, $exception);
        }

        $this->assertSame([WritingKey::class], array_map(get_class(...), $captured));
    }

    public function testPutManyDispatchesRepositoryFailureEventsWhenTheOperationThrows(): void
    {
        $exception = new RuntimeException('batch write failed');
        $values = ['first' => 'one', 'second' => 'two'];
        $putMany = Double::for(PutMany::class)->strict();
        $putMany->expects('execute')
            ->with($values, 60, ['users'])
            ->throws($exception);

        $operations = Double::for(AnyTagOperations::class)->strict();
        $operations->expects('putMany')->returns($putMany);
        $captured = [];
        $tagged = $this->taggedCacheWithOperations($operations, $captured);

        try {
            $tagged->putMany($values, 60);
            $this->fail('Expected the tagged cache batch write exception to be rethrown.');
        } catch (RuntimeException $caught) {
            $this->assertSame($exception, $caught);
        }

        $this->assertSame(
            [WritingManyKeys::class, KeyWriteFailed::class, KeyWriteFailed::class],
            array_map(get_class(...), $captured),
        );
        $this->assertSame(['first', 'second'], array_map(static fn (KeyWriteFailed $event): string => $event->key, array_slice($captured, 1)));
        $this->assertSame([$exception, $exception], array_map(static fn (KeyWriteFailed $event): RuntimeException => $event->exception, array_slice($captured, 1)));
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testPutManyDoesNotDispatchFailureEventsWhenTheOperationIsCanceled(bool $cleanupThrows): void
    {
        $cancellation = new CanceledException('batch write canceled');
        if ($cleanupThrows) {
            $cancellation = new RuntimeException('Cleanup failed.', previous: $cancellation);
        }
        $values = ['first' => 'one', 'second' => 'two'];
        $putMany = Double::for(PutMany::class)->strict();
        $putMany->expects('execute')
            ->with($values, 60, ['users'])
            ->throws($cancellation);

        $operations = Double::for(AnyTagOperations::class)->strict();
        $operations->expects('putMany')->returns($putMany);
        $captured = [];
        $tagged = $this->taggedCacheWithOperations($operations, $captured);

        try {
            $tagged->putMany($values, 60);
            $this->fail('Expected the tagged cache batch write cancellation to be rethrown.');
        } catch (Throwable $exception) {
            $this->assertSame($cancellation, $exception);
        }

        $this->assertSame([WritingManyKeys::class], array_map(get_class(...), $captured));
    }

    public function testEmptyPutManyReturnsTrueWithoutOperationOrEvents(): void
    {
        $store = Double::for(RedisStore::class)->strict();
        $store->expects('anyTagOps')->never();
        $tags = Double::for(AnyTagSet::class)->strict();
        $events = Double::for(Dispatcher::class)->strict();
        $events->expects('hasListeners')->never();
        $events->expects('dispatch')->never();
        $tagged = new AnyTaggedCache($store, $tags);
        $tagged->setEventDispatcher($events);

        $this->assertTrue($tagged->putMany([], 60));
    }

    public function testForeverDispatchesTheRepositoryFailureEventWhenTheOperationThrows(): void
    {
        $exception = new RuntimeException('forever write failed');
        $forever = Double::for(Forever::class)->strict();
        $forever->expects('execute')
            ->with('mykey', 'myvalue', ['users'])
            ->throws($exception);

        $operations = Double::for(AnyTagOperations::class)->strict();
        $operations->expects('forever')->returns($forever);
        $captured = [];
        $tagged = $this->taggedCacheWithOperations($operations, $captured);

        try {
            $tagged->forever('mykey', 'myvalue');
            $this->fail('Expected the tagged forever exception to be rethrown.');
        } catch (RuntimeException $caught) {
            $this->assertSame($exception, $caught);
        }

        $this->assertSame([WritingKey::class, KeyWriteFailed::class], array_map(get_class(...), $captured));
        $this->assertNull($captured[1]->seconds);
        $this->assertSame($exception, $captured[1]->exception);
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testForeverDoesNotDispatchFailureEventWhenTheOperationIsCanceled(bool $cleanupThrows): void
    {
        $cancellation = new CanceledException('forever write canceled');
        if ($cleanupThrows) {
            $cancellation = new RuntimeException('Cleanup failed.', previous: $cancellation);
        }
        $forever = Double::for(Forever::class)->strict();
        $forever->expects('execute')
            ->with('mykey', 'myvalue', ['users'])
            ->throws($cancellation);

        $operations = Double::for(AnyTagOperations::class)->strict();
        $operations->expects('forever')->returns($forever);
        $captured = [];
        $tagged = $this->taggedCacheWithOperations($operations, $captured);

        try {
            $tagged->forever('mykey', 'myvalue');
            $this->fail('Expected the tagged forever cancellation to be rethrown.');
        } catch (Throwable $exception) {
            $this->assertSame($cancellation, $exception);
        }

        $this->assertSame([WritingKey::class], array_map(get_class(...), $captured));
    }

    public function testRememberNullableStoresAndReturnsNonNullValue(): void
    {
        $connection = $this->mockConnection();

        $connection->client()->expects('get')->with('prefix:mykey')->returns(null);
        $connection->expects('evalWithShaCache')->returns(true);

        $store = $this->createStore($connection);
        $result = $store->setTagMode('any')->tags(['users'])->rememberNullable('mykey', 60, fn () => 'computed');

        $this->assertSame('computed', $result);
    }

    public function testRememberNullableStoresSentinelWhenCallbackReturnsNull(): void
    {
        $connection = $this->mockConnection();

        $connection->client()->expects('get')->with('prefix:mykey')->returns(null);

        $connection->expects('evalWithShaCache')
            ->with(Argument::all(function ($script, $keys, $args): bool {
                foreach (array_merge((array) $keys, (array) $args) as $arg) {
                    if (is_string($arg) && @unserialize($arg) === NullSentinel::VALUE) {
                        return true;
                    }
                }
                return false;
            }))
            ->returns(true);

        $store = $this->createStore($connection);
        $result = $store->setTagMode('any')->tags(['users'])->rememberNullable('mykey', 60, fn () => null);

        $this->assertNull($result);
    }

    public function testRememberNullableReturnsNullOnSentinelHitWithoutInvokingCallback(): void
    {
        $connection = $this->mockConnection();

        $connection->client()->expects('get')
            ->with('prefix:mykey')
            ->returns(serialize(NullSentinel::VALUE));

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

        $connection->client()->expects('get')
            ->with('prefix:mykey')
            ->returns(serialize(NullSentinel::VALUE));

        $captured = [];
        $events = Double::for(Dispatcher::class)->strict();
        $events->allows('hasListeners')->returns(true);
        $events->allows('dispatch')
            ->resolves(function ($event) use (&$captured) {
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

        $connection->client()->expects('get')->with('prefix:mykey')->returns(null);
        $connection->expects('evalWithShaCache')->returns(true);

        $captured = [];
        $events = Double::for(Dispatcher::class)->strict();
        $events->allows('hasListeners')->returns(true);
        $events->allows('dispatch')
            ->resolves(function ($event) use (&$captured) {
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

        $connection->client()->expects('get')
            ->with('prefix:mykey')
            ->returns(serialize(NullSentinel::VALUE));

        $invoked = false;
        $store = $this->createStore($connection);
        $result = $store->setTagMode('any')->tags(['users'])->remember('mykey', 60, function () use (&$invoked) {
            $invoked = true;
            return 'should-not-run';
        });

        $this->assertNull($result);
        $this->assertFalse($invoked);
    }

    public function testRememberForeverRetrievesExistingValueFromStore(): void
    {
        $connection = $this->mockConnection();

        $connection->client()->expects('get')
            ->with('prefix:mykey')
            ->returns(serialize('cached_value'));

        $store = $this->createStore($connection);
        $result = $store->setTagMode('any')->tags(['users'])->rememberForever('mykey', fn () => 'new_value');

        $this->assertSame('cached_value', $result);
    }

    public function testRememberForeverNormalizesEnumKeyForRedisAndEvents(): void
    {
        $connection = $this->mockConnection();

        $connection->client()->expects('get')
            ->with('prefix:1')
            ->returns(serialize('cached_settings'));

        $captured = [];
        $events = Double::for(Dispatcher::class)->strict();
        $events->allows('hasListeners')->returns(true);
        $events->allows('dispatch')
            ->resolves(function (object $event) use (&$captured): void {
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

    public function testRememberForeverCallsCallbackAndStoresValueWhenMiss(): void
    {
        $connection = $this->mockConnection();

        $connection->client()->expects('get')
            ->with('prefix:mykey')
            ->returns(null);

        // Should store the value forever with tags using evalWithShaCache
        $connection->expects('evalWithShaCache')
            ->returns(true);

        $store = $this->createStore($connection);
        $result = $store->setTagMode('any')->tags(['users'])->rememberForever('mykey', fn () => 'computed_value');

        $this->assertSame('computed_value', $result);
    }

    public function testRememberForeverNullableStoresSentinelWhenCallbackReturnsNull(): void
    {
        $connection = $this->mockConnection();

        $connection->client()->expects('get')->with('prefix:mykey')->returns(null);
        $connection->expects('evalWithShaCache')
            ->with(Argument::all(function ($script, $keys, $args): bool {
                foreach (array_merge((array) $keys, (array) $args) as $arg) {
                    if (is_string($arg) && @unserialize($arg) === NullSentinel::VALUE) {
                        return true;
                    }
                }
                return false;
            }))
            ->returns(true);

        $store = $this->createStore($connection);
        $result = $store->setTagMode('any')->tags(['users'])->rememberForeverNullable('mykey', fn () => null);

        $this->assertNull($result);
    }

    public function testSearNullableDelegatesToRememberForeverNullable(): void
    {
        $connection = $this->mockConnection();

        $connection->client()->expects('get')
            ->with('prefix:mykey')
            ->returns(serialize(NullSentinel::VALUE));

        $store = $this->createStore($connection);
        $result = $store->setTagMode('any')->tags(['users'])->searNullable('mykey', fn () => 'should-not-run');

        $this->assertNull($result);
    }

    public function testPlainRememberForeverUnwrapsSentinelOnCachedNullHit(): void
    {
        $connection = $this->mockConnection();

        $connection->client()->expects('get')
            ->with('prefix:mykey')
            ->returns(serialize(NullSentinel::VALUE));

        $invoked = false;
        $store = $this->createStore($connection);
        $result = $store->setTagMode('any')->tags(['users'])->rememberForever('mykey', function () use (&$invoked) {
            $invoked = true;
            return 'should-not-run';
        });

        $this->assertNull($result);
        $this->assertFalse($invoked);
    }

    public function testGetTagsReturnsTagSet(): void
    {
        $connection = $this->mockConnection();
        $store = $this->createStore($connection);
        $cache = $store->setTagMode('any')->tags(['users', 'posts']);

        $this->assertInstanceOf(AnyTagSet::class, $cache->getTags());
    }

    public function testItemKeyReturnsKeyUnchanged(): void
    {
        $connection = $this->mockConnection();

        // In any mode, keys are NOT namespaced by tags
        $connection->client()->expects('get')
            ->with('prefix:mykey') // Should NOT have tag namespace prefix
            ->returns(serialize('value'));

        $store = $this->createStore($connection);
        $store->setTagMode('any')->tags(['users'])->remember('mykey', 60, fn () => 'fallback');
    }

    public function testIncrementThrowsOnLuaFailure(): void
    {
        $connection = $this->mockConnection();

        $connection->expects('evalWithShaCache')
            ->throws(new LuaScriptException('Lua script execution failed'));

        $this->expectException(LuaScriptException::class);

        $store = $this->createStore($connection);
        $store->setTagMode('any')->tags(['users'])->increment('counter');
    }

    public function testDecrementThrowsOnLuaFailure(): void
    {
        $connection = $this->mockConnection();

        $connection->expects('evalWithShaCache')
            ->throws(new LuaScriptException('Lua script execution failed'));

        $this->expectException(LuaScriptException::class);

        $store = $this->createStore($connection);
        $store->setTagMode('any')->tags(['users'])->decrement('counter');
    }

    public function testRememberPropagatesExceptionFromCallback(): void
    {
        $connection = $this->mockConnection();

        // Client returns null (cache miss) - callback will be executed
        $connection->client()->expects('get')
            ->with('prefix:mykey')
            ->returns(null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('Callback failed');

        $store = $this->createStore($connection);
        $store->setTagMode('any')->tags(['users'])->remember('mykey', 60, function () {
            throw new RuntimeException('Callback failed');
        });
    }

    public function testRememberForeverPropagatesExceptionFromCallback(): void
    {
        $connection = $this->mockConnection();

        // Client returns null (cache miss) - callback will be executed
        $connection->client()->expects('get')
            ->with('prefix:mykey')
            ->returns(null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('Forever callback failed');

        $store = $this->createStore($connection);
        $store->setTagMode('any')->tags(['users'])->rememberForever('mykey', function () {
            throw new RuntimeException('Forever callback failed');
        });
    }

    public function testRememberDoesNotCallCallbackWhenValueExists(): void
    {
        $connection = $this->mockConnection();

        // Client returns existing value (cache hit)
        $connection->client()->expects('get')
            ->with('prefix:mykey')
            ->returns(serialize('cached_value'));

        $callCount = 0;
        $store = $this->createStore($connection);
        $result = $store->setTagMode('any')->tags(['users'])->remember('mykey', 60, function () use (&$callCount) {
            ++$callCount;

            return 'new_value';
        });

        $this->assertSame('cached_value', $result);
        $this->assertSame(0, $callCount, 'Callback should not be called when cache hit');
    }

    public function testItemsReturnsGenerator(): void
    {
        $connection = $this->mockConnection();

        // GetTaggedKeys uses hlen to check size first
        $connection->client()->expects('hlen')
            ->with('prefix:_any:tag:users:entries')
            ->returns(2);

        // When small (< threshold), it uses hkeys directly
        $connection->client()->expects('hkeys')
            ->with('prefix:_any:tag:users:entries')
            ->returns(['key1', 'key2']);

        // Get values for found keys (mget receives array)
        $connection->client()->expects('mget')
            ->with(['prefix:key1', 'prefix:key2'])
            ->returns([serialize('value1'), serialize('value2')]);

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
        $this->expectExceptionMessageIsOrContains('Cannot get items via tags in any mode');

        $cache->flexibleNullable('mykey', [60, 120], fn () => 'v');
    }

    private function taggedCacheWithOperations(AnyTagOperations $operations, array &$captured): AnyTaggedCache
    {
        $store = Double::for(RedisStore::class)->strict();
        $store->expects('anyTagOps')->returns($operations);

        $tags = Double::for(AnyTagSet::class)->strict();
        $tags->allows('getNames')->returns(['users']);

        $cache = new AnyTaggedCache($store, $tags);
        $store->expects('tags')->with(['users'])->returns($cache);

        $events = Double::for(Dispatcher::class)->strict();
        $events->allows('hasListeners')->returns(true);
        $events->allows('dispatch')->resolves(function (object $event) use (&$captured): void {
            $captured[] = $event;
        });

        $tagged = (new Repository($store, ['store' => 'redis']))->tags(['users']);
        $tagged->setEventDispatcher($events);

        return $tagged;
    }
}

enum AnyTaggedCacheTestKey: int
{
    case Profile = 0;
    case Settings = 1;
}
