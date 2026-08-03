<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis;

use Hypervel\Cache\Events\CacheFlushed;
use Hypervel\Cache\Events\CacheFlushing;
use Hypervel\Cache\Events\CacheHit;
use Hypervel\Cache\Events\CacheMissed;
use Hypervel\Cache\Events\KeyWriteFailed;
use Hypervel\Cache\Events\KeyWritten;
use Hypervel\Cache\Events\RetrievingKey;
use Hypervel\Cache\Events\RetrievingManyKeys;
use Hypervel\Cache\Events\WritingKey;
use Hypervel\Cache\Events\WritingManyKeys;
use Hypervel\Cache\NullSentinel;
use Hypervel\Cache\Redis\AllTaggedCache;
use Hypervel\Cache\Redis\AllTagSet;
use Hypervel\Cache\Repository;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Redis\PhpRedis;
use Mockery as m;
use RuntimeException;

/**
 * Tests for AllTaggedCache behavior.
 *
 * These tests verify the high-level API behavior of tagged cache operations.
 * For detailed operation tests, see tests/Cache/Redis/Operations/AllTag/.
 */
class AllTaggedCacheTest extends RedisCacheTestCase
{
    /**
     * @test
     */
    public function testTagEntriesCanBeStoredForever(): void
    {
        $connection = $this->mockConnection();

        $connection->shouldReceive('pipeline')->once()->andReturn($connection);

        $key = hash('xxh128', '_all:tag:people:entries|_all:tag:author:entries') . ':name';

        // Combined operation: ZADD for both tags + SET (forever uses score -1)
        $connection->shouldReceive('zadd')->once()->with('prefix:_all:tag:people:entries', -1, $key)->andReturn($connection);
        $connection->shouldReceive('zadd')->once()->with('prefix:_all:tag:author:entries', -1, $key)->andReturn($connection);
        $connection->shouldReceive('set')->once()->with("prefix:{$key}", serialize('Sally'))->andReturn($connection);
        $connection->shouldReceive('exec')->once()->andReturn([1, 1, true]);

        $store = $this->createStore($connection);
        $result = $store->tags(['people', 'author'])->forever('name', 'Sally');

        $this->assertTrue($result);
    }

    /**
     * @test
     */
    public function testTagEntriesCanBeStoredForeverWithNumericValue(): void
    {
        $connection = $this->mockConnection();

        $connection->shouldReceive('pipeline')->once()->andReturn($connection);

        $key = hash('xxh128', '_all:tag:people:entries|_all:tag:author:entries') . ':age';

        // Numeric values are NOT serialized (optimization)
        $connection->shouldReceive('zadd')->once()->with('prefix:_all:tag:people:entries', -1, $key)->andReturn($connection);
        $connection->shouldReceive('zadd')->once()->with('prefix:_all:tag:author:entries', -1, $key)->andReturn($connection);
        $connection->shouldReceive('set')->once()->with("prefix:{$key}", 30)->andReturn($connection);
        $connection->shouldReceive('exec')->once()->andReturn([1, 1, true]);

        $store = $this->createStore($connection);
        $result = $store->tags(['people', 'author'])->forever('age', 30);

        $this->assertTrue($result);
    }

    /**
     * @test
     */
    public function testTagEntriesCanBeIncremented(): void
    {
        $connection = $this->mockConnection();

        $connection->shouldReceive('pipeline')->once()->andReturn($connection);

        $key = hash('xxh128', '_all:tag:votes:entries') . ':person-1';

        // Combined operation: ZADD NX + INCRBY in single pipeline
        $connection->shouldReceive('zadd')->once()->with('prefix:_all:tag:votes:entries', ['NX'], -1, $key)->andReturn($connection);
        $connection->shouldReceive('incrby')->once()->with("prefix:{$key}", 1)->andReturn($connection);
        $connection->shouldReceive('exec')->once()->andReturn([1, 1]);

        $store = $this->createStore($connection);
        $result = $store->tags(['votes'])->increment('person-1');

        $this->assertSame(1, $result);
    }

    /**
     * @test
     */
    public function testTagEntriesCanBeDecremented(): void
    {
        $connection = $this->mockConnection();

        $connection->shouldReceive('pipeline')->once()->andReturn($connection);

        $key = hash('xxh128', '_all:tag:votes:entries') . ':person-1';

        // Combined operation: ZADD NX + DECRBY in single pipeline
        $connection->shouldReceive('zadd')->once()->with('prefix:_all:tag:votes:entries', ['NX'], -1, $key)->andReturn($connection);
        $connection->shouldReceive('decrby')->once()->with("prefix:{$key}", 1)->andReturn($connection);
        $connection->shouldReceive('exec')->once()->andReturn([1, 9]);

        $store = $this->createStore($connection);
        $result = $store->tags(['votes'])->decrement('person-1');

        $this->assertSame(9, $result);
    }

    /**
     * @test
     */
    public function testStaleEntriesCanBeFlushed(): void
    {
        $connection = $this->mockConnection();

        $connection->shouldReceive('pipeline')->once()->andReturn($connection);

        // FlushStaleEntries uses pipeline for zRemRangeByScore
        $connection->shouldReceive('zRemRangeByScore')
            ->once()
            ->with('prefix:_all:tag:people:entries', '0', (string) now()->timestamp)
            ->andReturn($connection);
        $connection->shouldReceive('exec')->once()->andReturn([0]);

        $store = $this->createStore($connection);
        $store->tags(['people'])->flushStale();
    }

    /**
     * @test
     */
    public function testPut(): void
    {
        $connection = $this->mockConnection();

        $connection->shouldReceive('pipeline')->once()->andReturn($connection);

        $key = hash('xxh128', '_all:tag:people:entries|_all:tag:author:entries') . ':name';
        $expectedScore = now()->timestamp + 5;

        // Combined operation: ZADD for both tags + SETEX in single pipeline
        $connection->shouldReceive('zadd')->once()->with('prefix:_all:tag:people:entries', $expectedScore, $key)->andReturn($connection);
        $connection->shouldReceive('zadd')->once()->with('prefix:_all:tag:author:entries', $expectedScore, $key)->andReturn($connection);
        $connection->shouldReceive('setex')->once()->with("prefix:{$key}", 5, serialize('Sally'))->andReturn($connection);
        $connection->shouldReceive('exec')->once()->andReturn([1, 1, true]);

        $store = $this->createStore($connection);
        $result = $store->tags(['people', 'author'])->put('name', 'Sally', 5);

        $this->assertTrue($result);
    }

    /**
     * @test
     */
    public function testPutWithNumericValue(): void
    {
        $connection = $this->mockConnection();

        $connection->shouldReceive('pipeline')->once()->andReturn($connection);

        $key = hash('xxh128', '_all:tag:people:entries|_all:tag:author:entries') . ':age';
        $expectedScore = now()->timestamp + 5;

        // Numeric values are NOT serialized
        $connection->shouldReceive('zadd')->once()->with('prefix:_all:tag:people:entries', $expectedScore, $key)->andReturn($connection);
        $connection->shouldReceive('zadd')->once()->with('prefix:_all:tag:author:entries', $expectedScore, $key)->andReturn($connection);
        $connection->shouldReceive('setex')->once()->with("prefix:{$key}", 5, 30)->andReturn($connection);
        $connection->shouldReceive('exec')->once()->andReturn([1, 1, true]);

        $store = $this->createStore($connection);
        $result = $store->tags(['people', 'author'])->put('age', 30, 5);

        $this->assertTrue($result);
    }

    /**
     * @test
     */
    public function testPutWithArray(): void
    {
        $connection = $this->mockConnection();

        $connection->shouldReceive('pipeline')->once()->andReturn($connection);

        $namespace = hash('xxh128', '_all:tag:people:entries|_all:tag:author:entries') . ':';
        $expectedScore = now()->timestamp + 5;

        // PutMany uses variadic ZADD: one command per tag with all keys as members
        // First tag (people) gets both keys in one ZADD
        $connection->shouldReceive('zadd')
            ->once()
            ->with('prefix:_all:tag:people:entries', $expectedScore, $namespace . 'name', $expectedScore, $namespace . 'age')
            ->andReturn($connection);

        // Second tag (author) gets both keys in one ZADD
        $connection->shouldReceive('zadd')
            ->once()
            ->with('prefix:_all:tag:author:entries', $expectedScore, $namespace . 'name', $expectedScore, $namespace . 'age')
            ->andReturn($connection);

        // SETEX for each key
        $connection->shouldReceive('setex')->once()->with("prefix:{$namespace}name", 5, serialize('Sally'))->andReturn($connection);
        $connection->shouldReceive('setex')->once()->with("prefix:{$namespace}age", 5, 30)->andReturn($connection);

        // Results: 2 ZADDs + 2 SETEXs
        $connection->shouldReceive('exec')->once()->andReturn([2, 2, true, true]);

        $store = $this->createStore($connection);
        $result = $store->tags(['people', 'author'])->put([
            'name' => 'Sally',
            'age' => 30,
        ], 5);

        $this->assertTrue($result);
    }

    public function testManyUsesSingleMgetWithCachedTagPrefix(): void
    {
        $connection = $this->mockConnection();
        $store = $this->createStore($connection);

        $namespace = '_all:tag:people:entries|_all:tag:author:entries';
        $prefix = hash('xxh128', $namespace) . ':';

        $connection->shouldReceive('mget')
            ->once()
            ->with([
                "prefix:{$prefix}name",
                "prefix:{$prefix}age",
                "prefix:{$prefix}missing",
            ])
            ->andReturn([
                serialize('Sally'),
                '30',
                null,
            ]);

        $tags = m::mock(AllTagSet::class, [$store, ['people', 'author']])->makePartial();
        $tags->shouldReceive('getNamespace')->once()->andReturn($namespace);

        $result = (new AllTaggedCache($store, $tags))->many([
            'name' => 'unused',
            'age',
            'missing' => 'fallback',
        ]);

        $this->assertSame([
            'name' => 'Sally',
            'age' => '30',
            'missing' => 'fallback',
        ], $result);
    }

    public function testManyFiresTaggedManyEvents(): void
    {
        $connection = $this->mockConnection();
        $key = hash('xxh128', '_all:tag:users:entries') . ':';
        $events = [];

        $connection->shouldReceive('mget')
            ->once()
            ->with(["prefix:{$key}profile", "prefix:{$key}missing"])
            ->andReturn([serialize('cached'), null]);

        $dispatcher = m::mock(Dispatcher::class);
        $dispatcher->shouldReceive('hasListeners')->withAnyArgs()->andReturnTrue();
        $dispatcher->shouldReceive('dispatch')->andReturnUsing(function (object $event) use (&$events): void {
            $events[] = $event;
        });

        $tagged = $this->createStore($connection)->tags(['users']);
        $tagged->setEventDispatcher($dispatcher);

        $this->assertSame([
            'profile' => 'cached',
            'missing' => null,
        ], $tagged->many(['profile', 'missing']));

        $this->assertCount(3, $events);
        $this->assertInstanceOf(RetrievingManyKeys::class, $events[0]);
        $this->assertSame(['profile', 'missing'], $events[0]->keys);
        $this->assertSame(['users'], $events[0]->tags);

        $this->assertInstanceOf(CacheHit::class, $events[1]);
        $this->assertSame('profile', $events[1]->key);
        $this->assertSame('cached', $events[1]->value);
        $this->assertSame(['users'], $events[1]->tags);

        $this->assertInstanceOf(CacheMissed::class, $events[2]);
        $this->assertSame('missing', $events[2]->key);
        $this->assertSame(['users'], $events[2]->tags);
    }

    /**
     * @test
     */
    public function testFlush(): void
    {
        $connection = $this->mockConnection();

        // Flush operation scans tag sets and deletes entries
        $connection->shouldReceive('zScan')
            ->once()
            ->with('prefix:_all:tag:people:entries', null, '*', 1000)
            ->andReturnUsing(function ($key, &$cursor) {
                $cursor = 0;

                return ['key1' => 0, 'key2' => 0];
            });
        // Delete cache entries
        $connection->shouldReceive('del')
            ->once()
            ->with('prefix:key1', 'prefix:key2')
            ->andReturn(2);

        // Delete tag set
        $connection->shouldReceive('del')
            ->once()
            ->with('prefix:_all:tag:people:entries')
            ->andReturn(1);

        $store = $this->createStore($connection);
        $result = $store->tags(['people'])->flush();

        $this->assertTrue($result);
    }

    /**
     * @test
     */
    public function testClearFlushesTaggedItems(): void
    {
        $connection = $this->mockConnection();

        $connection->shouldReceive('zScan')
            ->once()
            ->with('prefix:_all:tag:people:entries', null, '*', 1000)
            ->andReturnUsing(function ($key, &$cursor) {
                $cursor = 0;

                return ['key1' => 0, 'key2' => 0];
            });
        $connection->shouldReceive('del')
            ->once()
            ->with('prefix:key1', 'prefix:key2')
            ->andReturn(2);
        $connection->shouldReceive('del')
            ->once()
            ->with('prefix:_all:tag:people:entries')
            ->andReturn(1);

        $store = $this->createStore($connection);
        $result = $store->tags(['people'])->clear();

        $this->assertTrue($result);
    }

    /**
     * @test
     */
    public function testPutNullTtlCallsForever(): void
    {
        $connection = $this->mockConnection();

        $connection->shouldReceive('pipeline')->once()->andReturn($connection);

        $key = hash('xxh128', '_all:tag:users:entries') . ':name';

        // Null TTL should call forever (ZADD with -1 + SET)
        $connection->shouldReceive('zadd')->once()->with('prefix:_all:tag:users:entries', -1, $key)->andReturn($connection);
        $connection->shouldReceive('set')->once()->with("prefix:{$key}", serialize('John'))->andReturn($connection);
        $connection->shouldReceive('exec')->once()->andReturn([1, true]);

        $store = $this->createStore($connection);
        $result = $store->tags(['users'])->put('name', 'John', null);

        $this->assertTrue($result);
    }

    /**
     * @test
     */
    public function testPutZeroTtlDeletesKey(): void
    {
        $connection = $this->mockConnection();

        $key = hash('xxh128', '_all:tag:users:entries') . ':name';

        // Zero TTL should delete the key (Forget operation uses connection)
        $connection->shouldReceive('del')
            ->once()
            ->with("prefix:{$key}")
            ->andReturn(1);

        $store = $this->createStore($connection);
        $result = $store->tags(['users'])->put('name', 'John', 0);

        $this->assertTrue($result);
    }

    /**
     * @test
     */
    public function testPutManyZeroTtlDeletesNamespacedKeys(): void
    {
        $connection = $this->mockConnection();

        $namespace = hash('xxh128', '_all:tag:users:entries') . ':';

        $connection->shouldReceive('del')
            ->once()
            ->with("prefix:{$namespace}name")
            ->andReturn(1);
        $connection->shouldReceive('del')
            ->once()
            ->with("prefix:{$namespace}age")
            ->andReturn(1);

        $store = $this->createStore($connection);
        $result = $store->tags(['users'])->putMany([
            'name' => 'John',
            'age' => 30,
        ], 0);

        $this->assertTrue($result);
    }

    /**
     * @test
     */
    public function testTouchUpdatesKeyAndTagScores(): void
    {
        $connection = $this->mockConnection();

        $key = hash('xxh128', '_all:tag:users:entries') . ':name';

        $connection->shouldReceive('get')
            ->once()
            ->with("prefix:{$key}")
            ->andReturn(serialize('John'));
        $connection->shouldReceive('evalWithShaCache')
            ->once()
            ->withArgs(function (string $script, array $keys, array $args) use ($key): bool {
                $this->assertSame(["prefix:{$key}", 'prefix:_all:tag:users:entries'], $keys);
                $this->assertSame(60, $args[0]);
                $this->assertSame($key, $args[2]);

                return true;
            })
            ->andReturn(true);

        $store = $this->createStore($connection);
        $result = $store->tags(['users'])->touch('name', 60);

        $this->assertTrue($result);
    }

    /**
     * @test
     */
    public function testTouchUpdatesCachedNullKeyAndTagScores(): void
    {
        $connection = $this->mockConnection();

        $key = hash('xxh128', '_all:tag:users:entries') . ':name';

        $connection->shouldReceive('get')
            ->once()
            ->with("prefix:{$key}")
            ->andReturn(serialize(NullSentinel::VALUE));
        $connection->shouldReceive('evalWithShaCache')
            ->once()
            ->withArgs(function (string $script, array $keys, array $args) use ($key): bool {
                $this->assertSame(["prefix:{$key}", 'prefix:_all:tag:users:entries'], $keys);
                $this->assertSame(60, $args[0]);
                $this->assertSame($key, $args[2]);

                return true;
            })
            ->andReturn(true);

        $store = $this->createStore($connection);
        $result = $store->tags(['users'])->touch('name', 60);

        $this->assertTrue($result);
    }

    /**
     * @test
     */
    public function testTouchWithNullTtlStoresItemForeverWithTags(): void
    {
        $connection = $this->mockConnection();

        $key = hash('xxh128', '_all:tag:users:entries') . ':name';

        $connection->shouldReceive('get')
            ->once()
            ->with("prefix:{$key}")
            ->andReturn(serialize('John'));
        $connection->shouldReceive('pipeline')->once()->andReturn($connection);
        $connection->shouldReceive('zadd')->once()->with('prefix:_all:tag:users:entries', -1, $key)->andReturn($connection);
        $connection->shouldReceive('set')->once()->with("prefix:{$key}", serialize('John'))->andReturn($connection);
        $connection->shouldReceive('exec')->once()->andReturn([1, true]);

        $store = $this->createStore($connection);
        $result = $store->tags(['users'])->touch('name', null);

        $this->assertTrue($result);
    }

    /**
     * @test
     */
    public function testTouchWithNullTtlPreservesCachedNullSentinel(): void
    {
        $connection = $this->mockConnection();

        $key = hash('xxh128', '_all:tag:users:entries') . ':name';

        $connection->shouldReceive('get')
            ->once()
            ->with("prefix:{$key}")
            ->andReturn(serialize(NullSentinel::VALUE));
        $connection->shouldReceive('pipeline')->once()->andReturn($connection);
        $connection->shouldReceive('zadd')->once()->with('prefix:_all:tag:users:entries', -1, $key)->andReturn($connection);
        $connection->shouldReceive('set')
            ->once()
            ->with(
                "prefix:{$key}",
                m::on(fn (string $serialized): bool => unserialize($serialized) === NullSentinel::VALUE)
            )
            ->andReturn($connection);
        $connection->shouldReceive('exec')->once()->andReturn([1, true]);

        $store = $this->createStore($connection);
        $result = $store->tags(['users'])->touch('name', null);

        $this->assertTrue($result);
    }

    /**
     * @test
     */
    public function testTouchReturnsFalseForMissingKey(): void
    {
        $connection = $this->mockConnection();

        $key = hash('xxh128', '_all:tag:users:entries') . ':name';

        $connection->shouldReceive('get')
            ->once()
            ->with("prefix:{$key}")
            ->andReturnNull();
        $connection->shouldNotReceive('evalWithShaCache');
        $connection->shouldNotReceive('pipeline');

        $store = $this->createStore($connection);
        $result = $store->tags(['users'])->touch('name', 60);

        $this->assertFalse($result);
    }

    /**
     * @test
     */
    public function testIncrementWithCustomValue(): void
    {
        $connection = $this->mockConnection();

        $connection->shouldReceive('pipeline')->once()->andReturn($connection);

        $key = hash('xxh128', '_all:tag:counters:entries') . ':hits';

        $connection->shouldReceive('zadd')->once()->with('prefix:_all:tag:counters:entries', ['NX'], -1, $key)->andReturn($connection);
        $connection->shouldReceive('incrby')->once()->with("prefix:{$key}", 5)->andReturn($connection);
        $connection->shouldReceive('exec')->once()->andReturn([1, 15]);

        $store = $this->createStore($connection);
        $result = $store->tags(['counters'])->increment('hits', 5);

        $this->assertSame(15, $result);
    }

    /**
     * @test
     */
    public function testDecrementWithCustomValue(): void
    {
        $connection = $this->mockConnection();

        $connection->shouldReceive('pipeline')->once()->andReturn($connection);

        $key = hash('xxh128', '_all:tag:counters:entries') . ':stock';

        $connection->shouldReceive('zadd')->once()->with('prefix:_all:tag:counters:entries', ['NX'], -1, $key)->andReturn($connection);
        $connection->shouldReceive('decrby')->once()->with("prefix:{$key}", 3)->andReturn($connection);
        $connection->shouldReceive('exec')->once()->andReturn([0, 7]);

        $store = $this->createStore($connection);
        $result = $store->tags(['counters'])->decrement('stock', 3);

        $this->assertSame(7, $result);
    }

    /**
     * @test
     */
    public function testRememberReturnsExistingValueOnCacheHit(): void
    {
        $connection = $this->mockConnection();

        $key = hash('xxh128', '_all:tag:users:entries') . ':profile';

        $connection->shouldReceive('get')
            ->once()
            ->with("prefix:{$key}")
            ->andReturn(serialize('cached_value'));

        $store = $this->createStore($connection);
        $result = $store->tags(['users'])->remember('profile', 60, fn () => 'new_value');

        $this->assertSame('cached_value', $result);
    }

    /**
     * @test
     */
    public function testRememberCallsCallbackAndStoresValueOnMiss(): void
    {
        $connection = $this->mockConnection();

        $key = hash('xxh128', '_all:tag:users:entries') . ':profile';
        $expectedScore = now()->timestamp + 60;

        // Cache miss
        $connection->shouldReceive('get')
            ->once()
            ->with("prefix:{$key}")
            ->andReturnNull();

        // Pipeline for ZADD + SETEX on miss
        $connection->shouldReceive('pipeline')->once()->andReturn($connection);
        $connection->shouldReceive('zadd')->once()->with('prefix:_all:tag:users:entries', $expectedScore, $key)->andReturn($connection);
        $connection->shouldReceive('setex')->once()->with("prefix:{$key}", 60, serialize('computed_value'))->andReturn($connection);
        $connection->shouldReceive('exec')->once()->andReturn([1, true]);

        $callCount = 0;
        $store = $this->createStore($connection);
        $result = $store->tags(['users'])->remember('profile', 60, function () use (&$callCount) {
            ++$callCount;

            return 'computed_value';
        });

        $this->assertSame('computed_value', $result);
        $this->assertSame(1, $callCount);
    }

    public function testRememberNormalizesEnumKeyForRedisAndEvents(): void
    {
        $connection = $this->mockConnection();

        $key = hash('xxh128', '_all:tag:users:entries') . ':0';
        $expectedScore = now()->timestamp + 60;

        $connection->shouldReceive('get')->once()->with("prefix:{$key}")->andReturnNull();
        $connection->shouldReceive('pipeline')->once()->andReturn($connection);
        $connection->shouldReceive('zadd')->once()->with('prefix:_all:tag:users:entries', $expectedScore, $key)->andReturn($connection);
        $connection->shouldReceive('setex')->once()->with("prefix:{$key}", 60, serialize('computed_value'))->andReturn($connection);
        $connection->shouldReceive('exec')->once()->andReturn([1, true]);

        $captured = [];
        $events = m::mock(Dispatcher::class);
        $events->shouldReceive('hasListeners')->withAnyArgs()->andReturn(true);
        $events->shouldReceive('dispatch')
            ->andReturnUsing(function (object $event) use (&$captured): void {
                $captured[] = $event;
            });

        $tagged = $this->createStore($connection)->tags(['users']);
        $tagged->setEventDispatcher($events);

        $result = $tagged->remember(AllTaggedCacheTestKey::Profile, 60, fn () => 'computed_value');

        $this->assertSame('computed_value', $result);

        $cacheMissed = array_values(array_filter($captured, fn (object $event) => $event instanceof CacheMissed))[0] ?? null;
        $keyWritten = array_values(array_filter($captured, fn (object $event) => $event instanceof KeyWritten))[0] ?? null;

        $this->assertNotNull($cacheMissed);
        $this->assertSame('0', $cacheMissed->key);
        $this->assertNotNull($keyWritten);
        $this->assertSame('0', $keyWritten->key);
    }

    /**
     * @test
     */
    public function testRememberDoesNotCallCallbackOnCacheHit(): void
    {
        $connection = $this->mockConnection();

        $key = hash('xxh128', '_all:tag:users:entries') . ':data';

        $connection->shouldReceive('get')
            ->once()
            ->with("prefix:{$key}")
            ->andReturn(serialize('existing_value'));

        $callCount = 0;
        $store = $this->createStore($connection);
        $result = $store->tags(['users'])->remember('data', 60, function () use (&$callCount) {
            ++$callCount;

            return 'new_value';
        });

        $this->assertSame('existing_value', $result);
        $this->assertSame(0, $callCount, 'Callback should not be called on cache hit');
    }

    public function testRememberNullableStoresAndReturnsNonNullValue(): void
    {
        $connection = $this->mockConnection();
        $key = hash('xxh128', '_all:tag:users:entries') . ':profile';
        $expectedScore = now()->timestamp + 60;

        $connection->shouldReceive('get')->once()->with("prefix:{$key}")->andReturnNull();
        $connection->shouldReceive('pipeline')->once()->andReturn($connection);
        $connection->shouldReceive('zadd')->once()->with('prefix:_all:tag:users:entries', $expectedScore, $key)->andReturn($connection);
        $connection->shouldReceive('setex')->once()->with("prefix:{$key}", 60, serialize('computed'))->andReturn($connection);
        $connection->shouldReceive('exec')->once()->andReturn([1, true]);

        $store = $this->createStore($connection);
        $result = $store->tags(['users'])->rememberNullable('profile', 60, fn () => 'computed');

        $this->assertSame('computed', $result);
    }

    public function testRememberNullableStoresSentinelWhenCallbackReturnsNull(): void
    {
        $connection = $this->mockConnection();
        $key = hash('xxh128', '_all:tag:users:entries') . ':profile';
        $expectedScore = now()->timestamp + 60;

        $connection->shouldReceive('get')->once()->with("prefix:{$key}")->andReturnNull();
        $connection->shouldReceive('pipeline')->once()->andReturn($connection);
        $connection->shouldReceive('zadd')->once()->with('prefix:_all:tag:users:entries', $expectedScore, $key)->andReturn($connection);
        $connection->shouldReceive('setex')
            ->once()
            ->with(
                "prefix:{$key}",
                60,
                m::on(fn (string $serialized): bool => unserialize($serialized) === NullSentinel::VALUE)
            )
            ->andReturn($connection);
        $connection->shouldReceive('exec')->once()->andReturn([1, true]);

        $store = $this->createStore($connection);
        $result = $store->tags(['users'])->rememberNullable('profile', 60, fn () => null);

        $this->assertNull($result);
    }

    public function testRememberNullableReturnsNullOnSentinelHitWithoutInvokingCallback(): void
    {
        $connection = $this->mockConnection();
        $key = hash('xxh128', '_all:tag:users:entries') . ':profile';

        $connection->shouldReceive('get')
            ->once()
            ->with("prefix:{$key}")
            ->andReturn(serialize(NullSentinel::VALUE));

        $invoked = false;
        $store = $this->createStore($connection);
        $result = $store->tags(['users'])->rememberNullable('profile', 60, function () use (&$invoked) {
            $invoked = true;
            return 'should-not-run';
        });

        $this->assertNull($result);
        $this->assertFalse($invoked);
    }

    public function testRememberNullableFiresCacheHitWithNullPayloadOnSentinelHit(): void
    {
        $connection = $this->mockConnection();
        $key = hash('xxh128', '_all:tag:users:entries') . ':profile';

        $connection->shouldReceive('get')
            ->once()
            ->with("prefix:{$key}")
            ->andReturn(serialize(NullSentinel::VALUE));

        $captured = [];
        $events = m::mock(Dispatcher::class);
        $events->shouldReceive('hasListeners')->withAnyArgs()->andReturn(true);
        $events->shouldReceive('dispatch')
            ->andReturnUsing(function ($event) use (&$captured) {
                $captured[] = $event;
            });

        $store = $this->createStore($connection);
        $tagged = $store->tags(['users']);
        $tagged->setEventDispatcher($events);

        $tagged->rememberNullable('profile', 60, fn () => 'should-not-run');

        $cacheHit = array_values(array_filter($captured, fn ($e) => $e instanceof CacheHit))[0] ?? null;
        $this->assertNotNull($cacheHit);
        // Null, not the sentinel value.
        $this->assertNull($cacheHit->value);
    }

    public function testRememberNullableFiresKeyWrittenWithNullPayloadOnCacheMiss(): void
    {
        $connection = $this->mockConnection();
        $key = hash('xxh128', '_all:tag:users:entries') . ':profile';
        $expectedScore = now()->timestamp + 60;

        $connection->shouldReceive('get')->once()->with("prefix:{$key}")->andReturnNull();
        $connection->shouldReceive('pipeline')->once()->andReturn($connection);
        $connection->shouldReceive('zadd')->once()->with('prefix:_all:tag:users:entries', $expectedScore, $key)->andReturn($connection);
        $connection->shouldReceive('setex')->once()->andReturn($connection);
        $connection->shouldReceive('exec')->once()->andReturn([1, true]);

        $captured = [];
        $events = m::mock(Dispatcher::class);
        $events->shouldReceive('hasListeners')->withAnyArgs()->andReturn(true);
        $events->shouldReceive('dispatch')
            ->andReturnUsing(function ($event) use (&$captured) {
                $captured[] = $event;
            });

        $store = $this->createStore($connection);
        $tagged = $store->tags(['users']);
        $tagged->setEventDispatcher($events);

        $tagged->rememberNullable('profile', 60, fn () => null);

        $keyWritten = array_values(array_filter($captured, fn ($e) => $e instanceof KeyWritten))[0] ?? null;
        $this->assertNotNull($keyWritten);
        // Null, not the sentinel value.
        $this->assertNull($keyWritten->value);
    }

    /**
     * Proves tags()->remember() (plain, non-nullable) unwraps the sentinel on return
     * — the sentinel never leaks through the public non-nullable tagged-cache API.
     */
    public function testPlainRememberUnwrapsSentinelOnCachedNullHit(): void
    {
        $connection = $this->mockConnection();
        $key = hash('xxh128', '_all:tag:users:entries') . ':profile';

        $connection->shouldReceive('get')
            ->once()
            ->with("prefix:{$key}")
            ->andReturn(serialize(NullSentinel::VALUE));

        $invoked = false;
        $store = $this->createStore($connection);
        $result = $store->tags(['users'])->remember('profile', 60, function () use (&$invoked) {
            $invoked = true;
            return 'should-not-run';
        });

        $this->assertNull($result);
        $this->assertFalse($invoked);
    }

    /**
     * @test
     */
    public function testRememberForeverReturnsExistingValueOnCacheHit(): void
    {
        $connection = $this->mockConnection();

        $key = hash('xxh128', '_all:tag:config:entries') . ':settings';

        $connection->shouldReceive('get')
            ->once()
            ->with("prefix:{$key}")
            ->andReturn(serialize('cached_settings'));

        $store = $this->createStore($connection);
        $result = $store->tags(['config'])->rememberForever('settings', fn () => 'new_settings');

        $this->assertSame('cached_settings', $result);
    }

    public function testRememberForeverNormalizesEnumKeyForRedisAndEvents(): void
    {
        $connection = $this->mockConnection();

        $key = hash('xxh128', '_all:tag:config:entries') . ':1';

        $connection->shouldReceive('get')
            ->once()
            ->with("prefix:{$key}")
            ->andReturn(serialize('cached_settings'));

        $captured = [];
        $events = m::mock(Dispatcher::class);
        $events->shouldReceive('hasListeners')->withAnyArgs()->andReturn(true);
        $events->shouldReceive('dispatch')
            ->andReturnUsing(function (object $event) use (&$captured): void {
                $captured[] = $event;
            });

        $tagged = $this->createStore($connection)->tags(['config']);
        $tagged->setEventDispatcher($events);

        $result = $tagged->rememberForever(AllTaggedCacheTestKey::Settings, fn () => 'new_settings');

        $this->assertSame('cached_settings', $result);

        $cacheHit = array_values(array_filter($captured, fn (object $event) => $event instanceof CacheHit))[0] ?? null;

        $this->assertNotNull($cacheHit);
        $this->assertSame('1', $cacheHit->key);
    }

    /**
     * @test
     */
    public function testRememberForeverCallsCallbackAndStoresValueOnMiss(): void
    {
        $connection = $this->mockConnection();

        $key = hash('xxh128', '_all:tag:config:entries') . ':settings';

        // Cache miss
        $connection->shouldReceive('get')
            ->once()
            ->with("prefix:{$key}")
            ->andReturnNull();

        // Pipeline for ZADD (score -1) + SET on miss
        $connection->shouldReceive('pipeline')->once()->andReturn($connection);
        $connection->shouldReceive('zadd')->once()->with('prefix:_all:tag:config:entries', -1, $key)->andReturn($connection);
        $connection->shouldReceive('set')->once()->with("prefix:{$key}", serialize('computed_settings'))->andReturn($connection);
        $connection->shouldReceive('exec')->once()->andReturn([1, true]);

        $store = $this->createStore($connection);
        $result = $store->tags(['config'])->rememberForever('settings', fn () => 'computed_settings');

        $this->assertSame('computed_settings', $result);
    }

    public function testRememberForeverNullableStoresSentinelWhenCallbackReturnsNull(): void
    {
        $connection = $this->mockConnection();
        $key = hash('xxh128', '_all:tag:config:entries') . ':settings';

        $connection->shouldReceive('get')->once()->with("prefix:{$key}")->andReturnNull();
        $connection->shouldReceive('pipeline')->once()->andReturn($connection);
        $connection->shouldReceive('zadd')->once()->with('prefix:_all:tag:config:entries', -1, $key)->andReturn($connection);
        $connection->shouldReceive('set')
            ->once()
            ->with(
                "prefix:{$key}",
                m::on(fn (string $serialized): bool => unserialize($serialized) === NullSentinel::VALUE)
            )
            ->andReturn($connection);
        $connection->shouldReceive('exec')->once()->andReturn([1, true]);

        $store = $this->createStore($connection);
        $result = $store->tags(['config'])->rememberForeverNullable('settings', fn () => null);

        $this->assertNull($result);
    }

    public function testSearNullableDelegatesToRememberForeverNullable(): void
    {
        $connection = $this->mockConnection();
        $key = hash('xxh128', '_all:tag:config:entries') . ':settings';

        $connection->shouldReceive('get')->once()->with("prefix:{$key}")->andReturnNull();
        $connection->shouldReceive('pipeline')->once()->andReturn($connection);
        $connection->shouldReceive('zadd')->once()->with('prefix:_all:tag:config:entries', -1, $key)->andReturn($connection);
        $connection->shouldReceive('set')
            ->once()
            ->with(
                "prefix:{$key}",
                m::on(fn (string $serialized): bool => unserialize($serialized) === NullSentinel::VALUE)
            )
            ->andReturn($connection);
        $connection->shouldReceive('exec')->once()->andReturn([1, true]);

        $store = $this->createStore($connection);
        $result = $store->tags(['config'])->searNullable('settings', fn () => null);

        $this->assertNull($result);
    }

    /**
     * Same unwrap behavior on the forever variant.
     */
    public function testPlainRememberForeverUnwrapsSentinelOnCachedNullHit(): void
    {
        $connection = $this->mockConnection();
        $key = hash('xxh128', '_all:tag:config:entries') . ':settings';

        $connection->shouldReceive('get')
            ->once()
            ->with("prefix:{$key}")
            ->andReturn(serialize(NullSentinel::VALUE));

        $invoked = false;
        $store = $this->createStore($connection);
        $result = $store->tags(['config'])->rememberForever('settings', function () use (&$invoked) {
            $invoked = true;
            return 'should-not-run';
        });

        $this->assertNull($result);
        $this->assertFalse($invoked);
    }

    /**
     * @test
     */
    public function testRememberPropagatesExceptionFromCallback(): void
    {
        $connection = $this->mockConnection();

        $key = hash('xxh128', '_all:tag:users:entries') . ':data';

        $connection->shouldReceive('get')
            ->once()
            ->with("prefix:{$key}")
            ->andReturnNull();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Callback failed');

        $store = $this->createStore($connection);
        $store->tags(['users'])->remember('data', 60, function () {
            throw new RuntimeException('Callback failed');
        });
    }

    /**
     * @test
     */
    public function testRememberForeverPropagatesExceptionFromCallback(): void
    {
        $connection = $this->mockConnection();

        $key = hash('xxh128', '_all:tag:config:entries') . ':data';

        $connection->shouldReceive('get')
            ->once()
            ->with("prefix:{$key}")
            ->andReturnNull();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Forever callback failed');

        $store = $this->createStore($connection);
        $store->tags(['config'])->rememberForever('data', function () {
            throw new RuntimeException('Forever callback failed');
        });
    }

    /**
     * @test
     */
    public function testRememberWithMultipleTags(): void
    {
        $connection = $this->mockConnection();

        $key = hash('xxh128', '_all:tag:users:entries|_all:tag:posts:entries') . ':activity';
        $expectedScore = now()->timestamp + 120;

        // Cache miss
        $connection->shouldReceive('get')
            ->once()
            ->with("prefix:{$key}")
            ->andReturnNull();

        // Pipeline for ZADDs + SETEX on miss
        $connection->shouldReceive('pipeline')->once()->andReturn($connection);
        $connection->shouldReceive('zadd')->once()->with('prefix:_all:tag:users:entries', $expectedScore, $key)->andReturn($connection);
        $connection->shouldReceive('zadd')->once()->with('prefix:_all:tag:posts:entries', $expectedScore, $key)->andReturn($connection);
        $connection->shouldReceive('setex')->once()->with("prefix:{$key}", 120, serialize('activity_data'))->andReturn($connection);
        $connection->shouldReceive('exec')->once()->andReturn([1, 1, true]);

        $store = $this->createStore($connection);
        $result = $store->tags(['users', 'posts'])->remember('activity', 120, fn () => 'activity_data');

        $this->assertSame('activity_data', $result);
    }

    public function testFlexibleNullableReturnsNullOnFreshSentinelHit(): void
    {
        $connection = $this->mockConnection();
        $valueKey = hash('xxh128', '_all:tag:posts:entries') . ':digest';
        $markerKey = hash('xxh128', '_all:tag:posts:entries') . ':hypervel:cache:flexible:created:digest';
        $now = now()->timestamp;

        // flexible() reads both keys via a single manyRaw() → store->many() → MGET.
        // phpredis's mget() returns a numeric array in input order.
        $connection->shouldReceive('mget')
            ->once()
            ->with(["prefix:{$valueKey}", "prefix:{$markerKey}"])
            ->andReturn([serialize(NullSentinel::VALUE), serialize($now)]);

        $invoked = false;
        $store = $this->createStore($connection);
        $result = $store->tags(['posts'])->flexibleNullable('digest', [60, 120], function () use (&$invoked) {
            $invoked = true;
            return 'should-not-run';
        });

        $this->assertNull($result);
        $this->assertFalse($invoked);
    }

    public function testPutDispatchesTheRepositoryWriteEventsWithStoreNameAndTags(): void
    {
        $connection = $this->mockConnection();
        $key = hash('xxh128', '_all:tag:users:entries') . ':name';
        $score = now()->timestamp + 60;

        $connection->shouldReceive('pipeline')->once()->andReturn($connection);
        $connection->shouldReceive('zadd')
            ->once()
            ->with('prefix:_all:tag:users:entries', $score, $key)
            ->andReturn($connection);
        $connection->shouldReceive('setex')
            ->once()
            ->with("prefix:{$key}", 60, serialize('John'))
            ->andReturn($connection);
        $connection->shouldReceive('exec')->once()->andReturn([1, true]);

        $captured = [];
        $tagged = (new Repository($this->createStore($connection), ['store' => 'redis']))->tags(['users']);
        $tagged->setEventDispatcher($this->capturingDispatcher($captured));

        $this->assertTrue($tagged->put('name', 'John', 60));
        $this->assertSame([WritingKey::class, KeyWritten::class], array_map(get_class(...), $captured));

        foreach ($captured as $event) {
            $this->assertSame('redis', $event->storeName);
            $this->assertSame(['users'], $event->tags);
            $this->assertSame('name', $event->key);
            $this->assertSame('John', $event->value);
            $this->assertSame(60, $event->seconds);
        }
    }

    public function testAddWithoutTtlDispatchesRepositoryReadAndWriteEvents(): void
    {
        $connection = $this->mockConnection();
        $key = hash('xxh128', '_all:tag:users:entries') . ':name';

        $connection->shouldReceive('get')->once()->with("prefix:{$key}")->andReturnNull();
        $connection->shouldReceive('pipeline')->once()->andReturn($connection);
        $connection->shouldReceive('zadd')
            ->once()
            ->with('prefix:_all:tag:users:entries', -1, $key)
            ->andReturn($connection);
        $connection->shouldReceive('set')
            ->once()
            ->with("prefix:{$key}", serialize('John'))
            ->andReturn($connection);
        $connection->shouldReceive('exec')->once()->andReturn([1, true]);

        $captured = [];
        $tagged = (new Repository($this->createStore($connection), ['store' => 'redis']))->tags(['users']);
        $tagged->setEventDispatcher($this->capturingDispatcher($captured));

        $this->assertTrue($tagged->add('name', 'John'));
        $this->assertSame(
            [RetrievingKey::class, CacheMissed::class, WritingKey::class, KeyWritten::class],
            array_map(get_class(...), $captured)
        );

        foreach ($captured as $event) {
            $this->assertSame('redis', $event->storeName);
            $this->assertSame(['users'], $event->tags);
            $this->assertSame('name', $event->key);
        }
    }

    public function testPutDispatchesTheRepositoryFailureEventWithStoreNameAndTags(): void
    {
        $connection = $this->mockConnection();
        $key = hash('xxh128', '_all:tag:users:entries') . ':name';
        $score = now()->timestamp + 60;

        $connection->shouldReceive('pipeline')->once()->andReturn($connection);
        $connection->shouldReceive('zadd')
            ->once()
            ->with('prefix:_all:tag:users:entries', $score, $key)
            ->andReturn($connection);
        $connection->shouldReceive('setex')
            ->once()
            ->with("prefix:{$key}", 60, serialize('John'))
            ->andReturn($connection);
        $connection->shouldReceive('exec')->once()->andReturn([1, false]);

        $captured = [];
        $tagged = (new Repository($this->createStore($connection), ['store' => 'redis']))->tags(['users']);
        $tagged->setEventDispatcher($this->capturingDispatcher($captured));

        $this->assertFalse($tagged->put('name', 'John', 60));
        $this->assertSame([WritingKey::class, KeyWriteFailed::class], array_map(get_class(...), $captured));
        $this->assertSame('redis', $captured[1]->storeName);
        $this->assertSame(['users'], $captured[1]->tags);
    }

    public function testPutManyDispatchesTheRepositoryWriteEventsWithStoreNameAndTags(): void
    {
        $connection = $this->mockConnection();
        $namespace = hash('xxh128', '_all:tag:users:entries') . ':';
        $score = now()->timestamp + 60;

        $connection->shouldReceive('pipeline')->once()->andReturn($connection);
        $connection->shouldReceive('zadd')
            ->once()
            ->with(
                'prefix:_all:tag:users:entries',
                $score,
                $namespace . 'name',
                $score,
                $namespace . 'age'
            )
            ->andReturn($connection);
        $connection->shouldReceive('setex')
            ->once()
            ->with("prefix:{$namespace}name", 60, serialize('John'))
            ->andReturn($connection);
        $connection->shouldReceive('setex')
            ->once()
            ->with("prefix:{$namespace}age", 60, 30)
            ->andReturn($connection);
        $connection->shouldReceive('exec')->once()->andReturn([2, true, true]);

        $captured = [];
        $tagged = (new Repository($this->createStore($connection), ['store' => 'redis']))->tags(['users']);
        $tagged->setEventDispatcher($this->capturingDispatcher($captured));

        $this->assertTrue($tagged->putMany(['name' => 'John', 'age' => 30], 60));
        $this->assertSame(
            [WritingManyKeys::class, KeyWritten::class, KeyWritten::class],
            array_map(get_class(...), $captured)
        );
        $this->assertSame(['name', 'age'], $captured[0]->keys);
        $this->assertSame(['John', 30], $captured[0]->values);

        foreach ($captured as $event) {
            $this->assertSame('redis', $event->storeName);
            $this->assertSame(['users'], $event->tags);
        }
    }

    public function testFlushDispatchesTheRepositoryEventsWithStoreNameAndTags(): void
    {
        $connection = $this->mockConnection();
        $connection->shouldReceive('zScan')
            ->once()
            ->with('prefix:_all:tag:users:entries', PhpRedis::initialScanCursor(), '*', 1000)
            ->andReturnUsing(function ($key, &$cursor) {
                $cursor = 0;

                return [];
            });
        $connection->shouldReceive('del')
            ->once()
            ->with('prefix:_all:tag:users:entries')
            ->andReturn(1);

        $captured = [];
        $tagged = (new Repository($this->createStore($connection), ['store' => 'redis']))->tags(['users']);
        $tagged->setEventDispatcher($this->capturingDispatcher($captured));

        $this->assertTrue($tagged->flush());
        $this->assertSame([CacheFlushing::class, CacheFlushed::class], array_map(get_class(...), $captured));

        foreach ($captured as $event) {
            $this->assertSame('redis', $event->storeName);
            $this->assertSame(['users'], $event->tags);
        }
    }

    /**
     * Create an event dispatcher that records dispatched events.
     *
     * @param array<int, object> $captured
     */
    private function capturingDispatcher(array &$captured): Dispatcher
    {
        $dispatcher = m::mock(Dispatcher::class);
        $dispatcher->shouldReceive('hasListeners')->withAnyArgs()->andReturnTrue();
        $dispatcher->shouldReceive('dispatch')
            ->andReturnUsing(function (object $event) use (&$captured): void {
                $captured[] = $event;
            });

        return $dispatcher;
    }
}

enum AllTaggedCacheTestKey: int
{
    case Profile = 0;
    case Settings = 1;
}
