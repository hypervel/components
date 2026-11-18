<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis;

use Hypervel\Cache\Events\CacheFlushed;
use Hypervel\Cache\Events\CacheFlushFailed;
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
use Hypervel\Cache\Redis\Operations\AllTag\Flush;
use Hypervel\Cache\Redis\Operations\AllTag\Forever;
use Hypervel\Cache\Redis\Operations\AllTag\Put;
use Hypervel\Cache\Redis\Operations\AllTag\PutMany;
use Hypervel\Cache\Redis\Operations\AllTagOperations;
use Hypervel\Cache\RedisStore;
use Hypervel\Cache\Repository;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Tests\Redis\Fixtures\FakeRedisClient;
use JMac\Testing\Double;
use JMac\Testing\Matching\Argument;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Swoole\Coroutine\CanceledException;
use Throwable;

/**
 * Tests for AllTaggedCache behavior.
 *
 * These tests verify the high-level API behavior of tagged cache operations.
 * For detailed operation tests, see tests/Cache/Redis/Operations/AllTag/.
 */
class AllTaggedCacheTest extends RedisCacheTestCase
{
    public function testTagEntriesCanBeStoredForever(): void
    {
        $connection = $this->mockConnection();

        $connection->client()->expects('pipeline')->returns($connection->client());

        $key = hash('xxh128', '_all:tag:people:entries|_all:tag:author:entries') . ':name';

        // Combined operation: SET + ZADD for both tags (forever uses score -1)
        $connection->client()->expects('zadd')->with('prefix:_all:tag:people:entries', -1.0, $key)->returns($connection->client());
        $connection->client()->expects('zadd')->with('prefix:_all:tag:author:entries', -1.0, $key)->returns($connection->client());
        $connection->client()->expects('set')->with("prefix:{$key}", serialize('Sally'))->returns($connection->client());
        $connection->client()->expects('exec')->returns([true, 1, 1]);

        $store = $this->createStore($connection);
        $result = $store->tags(['people', 'author'])->forever('name', 'Sally');

        $this->assertTrue($result);
    }

    public function testTagEntriesCanBeStoredForeverWithNumericValue(): void
    {
        $connection = $this->mockConnection();

        $connection->client()->expects('pipeline')->returns($connection->client());

        $key = hash('xxh128', '_all:tag:people:entries|_all:tag:author:entries') . ':age';

        // Numeric values are NOT serialized (optimization)
        $connection->client()->expects('zadd')->with('prefix:_all:tag:people:entries', -1.0, $key)->returns($connection->client());
        $connection->client()->expects('zadd')->with('prefix:_all:tag:author:entries', -1.0, $key)->returns($connection->client());
        $connection->client()->expects('set')->with("prefix:{$key}", 30)->returns($connection->client());
        $connection->client()->expects('exec')->returns([true, 1, 1]);

        $store = $this->createStore($connection);
        $result = $store->tags(['people', 'author'])->forever('age', 30);

        $this->assertTrue($result);
    }

    public function testTagEntriesCanBeIncremented(): void
    {
        $connection = $this->mockConnection();

        $connection->client()->expects('pipeline')->returns($connection->client());

        $key = hash('xxh128', '_all:tag:votes:entries') . ':person-1';

        // Combined operation: INCRBY + ZADD NX in single pipeline
        $connection->client()->expects('zadd')->with('prefix:_all:tag:votes:entries', ['NX'], -1, $key)->returns($connection->client());
        $connection->client()->expects('incrby')->with("prefix:{$key}", 1)->returns($connection->client());
        $connection->client()->expects('exec')->returns([1, 1]);

        $store = $this->createStore($connection);
        $result = $store->tags(['votes'])->increment('person-1');

        $this->assertSame(1, $result);
    }

    public function testTagEntriesCanBeDecremented(): void
    {
        $connection = $this->mockConnection();

        $connection->client()->expects('pipeline')->returns($connection->client());

        $key = hash('xxh128', '_all:tag:votes:entries') . ':person-1';

        // Combined operation: DECRBY + ZADD NX in single pipeline
        $connection->client()->expects('zadd')->with('prefix:_all:tag:votes:entries', ['NX'], -1, $key)->returns($connection->client());
        $connection->client()->expects('decrby')->with("prefix:{$key}", 1)->returns($connection->client());
        $connection->client()->expects('exec')->returns([9, 1]);

        $store = $this->createStore($connection);
        $result = $store->tags(['votes'])->decrement('person-1');

        $this->assertSame(9, $result);
    }

    public function testStaleEntriesCanBeFlushed(): void
    {
        $connection = $this->mockConnection();

        $connection->client()->expects('pipeline')->returns($connection->client());

        // FlushStaleEntries uses pipeline for zRemRangeByScore
        $connection->client()->expects('zRemRangeByScore')
            ->with('prefix:_all:tag:people:entries', '0', (string) now()->timestamp)
            ->returns($connection->client());
        $connection->client()->expects('exec')->returns([0]);

        $store = $this->createStore($connection);
        $store->tags(['people'])->flushStale();
    }

    public function testPut(): void
    {
        $connection = $this->mockConnection();

        $connection->client()->expects('pipeline')->returns($connection->client());

        $key = hash('xxh128', '_all:tag:people:entries|_all:tag:author:entries') . ':name';
        $expectedScore = now()->timestamp + 5;

        // Combined operation: SETEX + ZADD for both tags in single pipeline
        $connection->client()->expects('zadd')->with('prefix:_all:tag:people:entries', (float) $expectedScore, $key)->returns($connection->client());
        $connection->client()->expects('zadd')->with('prefix:_all:tag:author:entries', (float) $expectedScore, $key)->returns($connection->client());
        $connection->client()->expects('setex')->with("prefix:{$key}", 5, serialize('Sally'))->returns($connection->client());
        $connection->client()->expects('exec')->returns([true, 1, 1]);

        $store = $this->createStore($connection);
        $result = $store->tags(['people', 'author'])->put('name', 'Sally', 5);

        $this->assertTrue($result);
    }

    public function testPutWithNumericValue(): void
    {
        $connection = $this->mockConnection();

        $connection->client()->expects('pipeline')->returns($connection->client());

        $key = hash('xxh128', '_all:tag:people:entries|_all:tag:author:entries') . ':age';
        $expectedScore = now()->timestamp + 5;

        // Numeric values are NOT serialized
        $connection->client()->expects('zadd')->with('prefix:_all:tag:people:entries', (float) $expectedScore, $key)->returns($connection->client());
        $connection->client()->expects('zadd')->with('prefix:_all:tag:author:entries', (float) $expectedScore, $key)->returns($connection->client());
        $connection->client()->expects('setex')->with("prefix:{$key}", 5, 30)->returns($connection->client());
        $connection->client()->expects('exec')->returns([true, 1, 1]);

        $store = $this->createStore($connection);
        $result = $store->tags(['people', 'author'])->put('age', 30, 5);

        $this->assertTrue($result);
    }

    public function testPutWithArray(): void
    {
        $connection = $this->mockConnection();

        $connection->client()->expects('pipeline')->returns($connection->client());

        $namespace = hash('xxh128', '_all:tag:people:entries|_all:tag:author:entries') . ':';
        $expectedScore = now()->timestamp + 5;

        // PutMany uses variadic ZADD: one command per tag with all keys as members
        // First tag (people) gets both keys in one ZADD
        $connection->client()->expects('zadd')
            ->with('prefix:_all:tag:people:entries', (float) $expectedScore, $namespace . 'name', $expectedScore, $namespace . 'age')
            ->returns($connection->client());

        // Second tag (author) gets both keys in one ZADD
        $connection->client()->expects('zadd')
            ->with('prefix:_all:tag:author:entries', (float) $expectedScore, $namespace . 'name', $expectedScore, $namespace . 'age')
            ->returns($connection->client());

        // SETEX for each key
        $connection->client()->expects('setex')->with("prefix:{$namespace}name", 5, serialize('Sally'))->returns($connection->client());
        $connection->client()->expects('setex')->with("prefix:{$namespace}age", 5, 30)->returns($connection->client());

        // Results: 2 SETEXs + 2 ZADDs
        $connection->client()->expects('exec')->returns([true, true, 2, 2]);

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

        $connection->client()->expects('mget')
            ->with([
                "prefix:{$prefix}name",
                "prefix:{$prefix}age",
                "prefix:{$prefix}missing",
            ])
            ->returns([
                serialize('Sally'),
                '30',
                null,
            ]);

        $tags = Double::for(new AllTagSet($store, ['people', 'author']))->passthru();
        $tags->expects('getNamespace')->returns($namespace);

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

        $connection->client()->expects('mget')
            ->with(["prefix:{$key}profile", "prefix:{$key}missing"])
            ->returns([serialize('cached'), null]);

        $dispatcher = Double::for(Dispatcher::class)->strict();
        $dispatcher->allows('hasListeners')->returns(true);
        $dispatcher->allows('dispatch')->resolves(function (object $event) use (&$events): void {
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

    public function testFlush(): void
    {
        $client = new FakeRedisClient(
            zScanResults: [
                'prefix:_all:tag:people:entries' => [
                    ['members' => ['key1' => 0, 'key2' => 0], 'iterator' => 0],
                ],
            ],
            evalShaResults: [1],
        );
        $store = $this->createStoreWithFakeClient($client);

        $this->assertTrue($store->tags(['people'])->flush());
        $this->assertSame([
            ['key' => 'prefix:_all:tag:people:entries', 'iterator' => null, 'pattern' => '*', 'count' => 1000],
        ], $client->getZScanCalls());
        $calls = $client->getEvalShaCalls();
        $this->assertCount(1, $calls);
        $this->assertSame(['prefix:_all:tag:people:entries', 'prefix:key1', 'prefix:key2', 'key1', 'key2'], $calls[0]['args']);
        $this->assertSame(3, $calls[0]['num_keys']);
    }

    public function testClearFlushesTaggedItems(): void
    {
        $client = new FakeRedisClient(
            zScanResults: [
                'prefix:_all:tag:people:entries' => [
                    ['members' => ['key1' => 0, 'key2' => 0], 'iterator' => 0],
                ],
            ],
            evalShaResults: [1],
        );
        $store = $this->createStoreWithFakeClient($client);

        $this->assertTrue($store->tags(['people'])->clear());
        $this->assertSame([
            ['key' => 'prefix:_all:tag:people:entries', 'iterator' => null, 'pattern' => '*', 'count' => 1000],
        ], $client->getZScanCalls());
        $calls = $client->getEvalShaCalls();
        $this->assertCount(1, $calls);
        $this->assertSame(['prefix:_all:tag:people:entries', 'prefix:key1', 'prefix:key2', 'key1', 'key2'], $calls[0]['args']);
        $this->assertSame(3, $calls[0]['num_keys']);
    }

    public function testPutNullTtlCallsForever(): void
    {
        $connection = $this->mockConnection();

        $connection->client()->expects('pipeline')->returns($connection->client());

        $key = hash('xxh128', '_all:tag:users:entries') . ':name';

        // Null TTL should call forever (SET + ZADD with -1)
        $connection->client()->expects('zadd')->with('prefix:_all:tag:users:entries', -1.0, $key)->returns($connection->client());
        $connection->client()->expects('set')->with("prefix:{$key}", serialize('John'))->returns($connection->client());
        $connection->client()->expects('exec')->returns([true, 1]);

        $store = $this->createStore($connection);
        $result = $store->tags(['users'])->put('name', 'John', null);

        $this->assertTrue($result);
    }

    public function testPutZeroTtlDeletesKey(): void
    {
        $connection = $this->mockConnection();

        $key = hash('xxh128', '_all:tag:users:entries') . ':name';

        // Zero TTL should delete the key (Forget operation uses connection)
        $connection->client()->expects('del')
            ->with("prefix:{$key}")
            ->returns(1);

        $store = $this->createStore($connection);
        $result = $store->tags(['users'])->put('name', 'John', 0);

        $this->assertTrue($result);
    }

    public function testPutManyZeroTtlDeletesNamespacedKeys(): void
    {
        $connection = $this->mockConnection();

        $namespace = hash('xxh128', '_all:tag:users:entries') . ':';

        $connection->client()->expects('del')
            ->with("prefix:{$namespace}name")
            ->returns(1);
        $connection->client()->expects('del')
            ->with("prefix:{$namespace}age")
            ->returns(1);

        $store = $this->createStore($connection);
        $result = $store->tags(['users'])->putMany([
            'name' => 'John',
            'age' => 30,
        ], 0);

        $this->assertTrue($result);
    }

    #[TestWith([true])]
    #[TestWith([false])]
    public function testTouchUpdatesKeyAndTagScores(bool $succeeded): void
    {
        $connection = $this->mockConnection();

        $key = hash('xxh128', '_all:tag:users:entries') . ':name';

        $connection->expects('evalWithShaCache')
            ->with(Argument::all(function (string $script, array $keys, array $args) use ($key): bool {
                $this->assertSame(["prefix:{$key}", 'prefix:_all:tag:users:entries'], $keys);
                $this->assertSame(60, $args[0]);
                $this->assertSame($key, $args[2]);

                return true;
            }))
            ->returns($succeeded);

        $store = $this->createStore($connection);
        $result = $store->tags(['users'])->touch('name', 60);

        $this->assertSame($succeeded, $result);
    }

    public function testTouchWithDatetimeInPastOrZeroSecondsRemovesOldItem(): void
    {
        $connection = $this->mockConnection();

        $key = hash('xxh128', '_all:tag:users:entries') . ':name';

        $connection->expects('evalWithShaCache')->never();
        $connection->client()->expects('del')->times(2)->with("prefix:{$key}")->returns(1);

        $store = $this->createStore($connection);
        $tagged = $store->tags(['users']);

        $this->assertTrue($tagged->touch('name', CarbonImmutable::now()->subMinute()));
        $this->assertTrue($tagged->touch('name', 0));
    }

    public function testIncrementWithCustomValue(): void
    {
        $connection = $this->mockConnection();

        $connection->client()->expects('pipeline')->returns($connection->client());

        $key = hash('xxh128', '_all:tag:counters:entries') . ':hits';

        $connection->client()->expects('zadd')->with('prefix:_all:tag:counters:entries', ['NX'], -1, $key)->returns($connection->client());
        $connection->client()->expects('incrby')->with("prefix:{$key}", 5)->returns($connection->client());
        $connection->client()->expects('exec')->returns([15, 1]);

        $store = $this->createStore($connection);
        $result = $store->tags(['counters'])->increment('hits', 5);

        $this->assertSame(15, $result);
    }

    public function testDecrementWithCustomValue(): void
    {
        $connection = $this->mockConnection();

        $connection->client()->expects('pipeline')->returns($connection->client());

        $key = hash('xxh128', '_all:tag:counters:entries') . ':stock';

        $connection->client()->expects('zadd')->with('prefix:_all:tag:counters:entries', ['NX'], -1, $key)->returns($connection->client());
        $connection->client()->expects('decrby')->with("prefix:{$key}", 3)->returns($connection->client());
        $connection->client()->expects('exec')->returns([7, 0]);

        $store = $this->createStore($connection);
        $result = $store->tags(['counters'])->decrement('stock', 3);

        $this->assertSame(7, $result);
    }

    public function testRememberReturnsExistingValueOnCacheHit(): void
    {
        $connection = $this->mockConnection();

        $key = hash('xxh128', '_all:tag:users:entries') . ':profile';

        $connection->client()->expects('get')
            ->with("prefix:{$key}")
            ->returns(serialize('cached_value'));

        $store = $this->createStore($connection);
        $result = $store->tags(['users'])->remember('profile', 60, fn () => 'new_value');

        $this->assertSame('cached_value', $result);
    }

    public function testRememberCallsCallbackAndStoresValueOnMiss(): void
    {
        $connection = $this->mockConnection();

        $key = hash('xxh128', '_all:tag:users:entries') . ':profile';
        $expectedScore = now()->timestamp + 60;

        // Cache miss
        $connection->client()->expects('get')
            ->with("prefix:{$key}")
            ->returns(null);

        // Pipeline for SETEX + ZADD on miss
        $connection->client()->expects('pipeline')->returns($connection->client());
        $connection->client()->expects('zadd')->with('prefix:_all:tag:users:entries', (float) $expectedScore, $key)->returns($connection->client());
        $connection->client()->expects('setex')->with("prefix:{$key}", 60, serialize('computed_value'))->returns($connection->client());
        $connection->client()->expects('exec')->returns([true, 1]);

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

        $connection->client()->expects('get')->with("prefix:{$key}")->returns(null);
        $connection->client()->expects('pipeline')->returns($connection->client());
        $connection->client()->expects('zadd')->with('prefix:_all:tag:users:entries', (float) $expectedScore, $key)->returns($connection->client());
        $connection->client()->expects('setex')->with("prefix:{$key}", 60, serialize('computed_value'))->returns($connection->client());
        $connection->client()->expects('exec')->returns([true, 1]);

        $captured = [];
        $events = Double::for(Dispatcher::class)->strict();
        $events->allows('hasListeners')->returns(true);
        $events->allows('dispatch')
            ->resolves(function (object $event) use (&$captured): void {
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

    public function testRememberDoesNotCallCallbackOnCacheHit(): void
    {
        $connection = $this->mockConnection();

        $key = hash('xxh128', '_all:tag:users:entries') . ':data';

        $connection->client()->expects('get')
            ->with("prefix:{$key}")
            ->returns(serialize('existing_value'));

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

        $connection->client()->expects('get')->with("prefix:{$key}")->returns(null);
        $connection->client()->expects('pipeline')->returns($connection->client());
        $connection->client()->expects('zadd')->with('prefix:_all:tag:users:entries', (float) $expectedScore, $key)->returns($connection->client());
        $connection->client()->expects('setex')->with("prefix:{$key}", 60, serialize('computed'))->returns($connection->client());
        $connection->client()->expects('exec')->returns([true, 1]);

        $store = $this->createStore($connection);
        $result = $store->tags(['users'])->rememberNullable('profile', 60, fn () => 'computed');

        $this->assertSame('computed', $result);
    }

    public function testRememberNullableStoresSentinelWhenCallbackReturnsNull(): void
    {
        $connection = $this->mockConnection();
        $key = hash('xxh128', '_all:tag:users:entries') . ':profile';
        $expectedScore = now()->timestamp + 60;

        $connection->client()->expects('get')->with("prefix:{$key}")->returns(null);
        $connection->client()->expects('pipeline')->returns($connection->client());
        $connection->client()->expects('zadd')->with('prefix:_all:tag:users:entries', (float) $expectedScore, $key)->returns($connection->client());
        $connection->client()->expects('setex')
            ->with(
                "prefix:{$key}",
                60,
                Argument::satisfies(fn (string $serialized): bool => unserialize($serialized) === NullSentinel::VALUE)
            )
            ->returns($connection->client());
        $connection->client()->expects('exec')->returns([true, 1]);

        $store = $this->createStore($connection);
        $result = $store->tags(['users'])->rememberNullable('profile', 60, fn () => null);

        $this->assertNull($result);
    }

    public function testRememberNullableReturnsNullOnSentinelHitWithoutInvokingCallback(): void
    {
        $connection = $this->mockConnection();
        $key = hash('xxh128', '_all:tag:users:entries') . ':profile';

        $connection->client()->expects('get')
            ->with("prefix:{$key}")
            ->returns(serialize(NullSentinel::VALUE));

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

        $connection->client()->expects('get')
            ->with("prefix:{$key}")
            ->returns(serialize(NullSentinel::VALUE));

        $captured = [];
        $events = Double::for(Dispatcher::class)->strict();
        $events->allows('hasListeners')->returns(true);
        $events->allows('dispatch')
            ->resolves(function ($event) use (&$captured) {
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

        $connection->client()->expects('get')->with("prefix:{$key}")->returns(null);
        $connection->client()->expects('pipeline')->returns($connection->client());
        $connection->client()->expects('zadd')->with('prefix:_all:tag:users:entries', (float) $expectedScore, $key)->returns($connection->client());
        $connection->client()->expects('setex')->returns($connection->client());
        $connection->client()->expects('exec')->returns([true, 1]);

        $captured = [];
        $events = Double::for(Dispatcher::class)->strict();
        $events->allows('hasListeners')->returns(true);
        $events->allows('dispatch')
            ->resolves(function ($event) use (&$captured) {
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

        $connection->client()->expects('get')
            ->with("prefix:{$key}")
            ->returns(serialize(NullSentinel::VALUE));

        $invoked = false;
        $store = $this->createStore($connection);
        $result = $store->tags(['users'])->remember('profile', 60, function () use (&$invoked) {
            $invoked = true;
            return 'should-not-run';
        });

        $this->assertNull($result);
        $this->assertFalse($invoked);
    }

    public function testRememberForeverReturnsExistingValueOnCacheHit(): void
    {
        $connection = $this->mockConnection();

        $key = hash('xxh128', '_all:tag:config:entries') . ':settings';

        $connection->client()->expects('get')
            ->with("prefix:{$key}")
            ->returns(serialize('cached_settings'));

        $store = $this->createStore($connection);
        $result = $store->tags(['config'])->rememberForever('settings', fn () => 'new_settings');

        $this->assertSame('cached_settings', $result);
    }

    public function testRememberForeverNormalizesEnumKeyForRedisAndEvents(): void
    {
        $connection = $this->mockConnection();

        $key = hash('xxh128', '_all:tag:config:entries') . ':1';

        $connection->client()->expects('get')
            ->with("prefix:{$key}")
            ->returns(serialize('cached_settings'));

        $captured = [];
        $events = Double::for(Dispatcher::class)->strict();
        $events->allows('hasListeners')->returns(true);
        $events->allows('dispatch')
            ->resolves(function (object $event) use (&$captured): void {
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

    public function testRememberForeverCallsCallbackAndStoresValueOnMiss(): void
    {
        $connection = $this->mockConnection();

        $key = hash('xxh128', '_all:tag:config:entries') . ':settings';

        // Cache miss
        $connection->client()->expects('get')
            ->with("prefix:{$key}")
            ->returns(null);

        // Pipeline for SET + ZADD (score -1) on miss
        $connection->client()->expects('pipeline')->returns($connection->client());
        $connection->client()->expects('zadd')->with('prefix:_all:tag:config:entries', -1.0, $key)->returns($connection->client());
        $connection->client()->expects('set')->with("prefix:{$key}", serialize('computed_settings'))->returns($connection->client());
        $connection->client()->expects('exec')->returns([true, 1]);

        $store = $this->createStore($connection);
        $result = $store->tags(['config'])->rememberForever('settings', fn () => 'computed_settings');

        $this->assertSame('computed_settings', $result);
    }

    public function testRememberForeverNullableStoresSentinelWhenCallbackReturnsNull(): void
    {
        $connection = $this->mockConnection();
        $key = hash('xxh128', '_all:tag:config:entries') . ':settings';

        $connection->client()->expects('get')->with("prefix:{$key}")->returns(null);
        $connection->client()->expects('pipeline')->returns($connection->client());
        $connection->client()->expects('zadd')->with('prefix:_all:tag:config:entries', -1.0, $key)->returns($connection->client());
        $connection->client()->expects('set')
            ->with(
                "prefix:{$key}",
                Argument::satisfies(fn (string $serialized): bool => unserialize($serialized) === NullSentinel::VALUE)
            )
            ->returns($connection->client());
        $connection->client()->expects('exec')->returns([true, 1]);

        $store = $this->createStore($connection);
        $result = $store->tags(['config'])->rememberForeverNullable('settings', fn () => null);

        $this->assertNull($result);
    }

    public function testSearNullableDelegatesToRememberForeverNullable(): void
    {
        $connection = $this->mockConnection();
        $key = hash('xxh128', '_all:tag:config:entries') . ':settings';

        $connection->client()->expects('get')->with("prefix:{$key}")->returns(null);
        $connection->client()->expects('pipeline')->returns($connection->client());
        $connection->client()->expects('zadd')->with('prefix:_all:tag:config:entries', -1.0, $key)->returns($connection->client());
        $connection->client()->expects('set')
            ->with(
                "prefix:{$key}",
                Argument::satisfies(fn (string $serialized): bool => unserialize($serialized) === NullSentinel::VALUE)
            )
            ->returns($connection->client());
        $connection->client()->expects('exec')->returns([true, 1]);

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

        $connection->client()->expects('get')
            ->with("prefix:{$key}")
            ->returns(serialize(NullSentinel::VALUE));

        $invoked = false;
        $store = $this->createStore($connection);
        $result = $store->tags(['config'])->rememberForever('settings', function () use (&$invoked) {
            $invoked = true;
            return 'should-not-run';
        });

        $this->assertNull($result);
        $this->assertFalse($invoked);
    }

    public function testRememberPropagatesExceptionFromCallback(): void
    {
        $connection = $this->mockConnection();

        $key = hash('xxh128', '_all:tag:users:entries') . ':data';

        $connection->client()->expects('get')
            ->with("prefix:{$key}")
            ->returns(null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('Callback failed');

        $store = $this->createStore($connection);
        $store->tags(['users'])->remember('data', 60, function () {
            throw new RuntimeException('Callback failed');
        });
    }

    public function testRememberForeverPropagatesExceptionFromCallback(): void
    {
        $connection = $this->mockConnection();

        $key = hash('xxh128', '_all:tag:config:entries') . ':data';

        $connection->client()->expects('get')
            ->with("prefix:{$key}")
            ->returns(null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('Forever callback failed');

        $store = $this->createStore($connection);
        $store->tags(['config'])->rememberForever('data', function () {
            throw new RuntimeException('Forever callback failed');
        });
    }

    public function testRememberWithMultipleTags(): void
    {
        $connection = $this->mockConnection();

        $key = hash('xxh128', '_all:tag:users:entries|_all:tag:posts:entries') . ':activity';
        $expectedScore = now()->timestamp + 120;

        // Cache miss
        $connection->client()->expects('get')
            ->with("prefix:{$key}")
            ->returns(null);

        // Pipeline for SETEX + ZADDs on miss
        $connection->client()->expects('pipeline')->returns($connection->client());
        $connection->client()->expects('zadd')->with('prefix:_all:tag:users:entries', (float) $expectedScore, $key)->returns($connection->client());
        $connection->client()->expects('zadd')->with('prefix:_all:tag:posts:entries', (float) $expectedScore, $key)->returns($connection->client());
        $connection->client()->expects('setex')->with("prefix:{$key}", 120, serialize('activity_data'))->returns($connection->client());
        $connection->client()->expects('exec')->returns([true, 1, 1]);

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
        $connection->client()->expects('mget')
            ->with(["prefix:{$valueKey}", "prefix:{$markerKey}"])
            ->returns([serialize(NullSentinel::VALUE), serialize($now)]);

        $invoked = false;
        $store = $this->createStore($connection);
        $result = $store->tags(['posts'])->flexibleNullable('digest', [60, 120], function () use (&$invoked) {
            $invoked = true;
            return 'should-not-run';
        });

        $this->assertNull($result);
        $this->assertFalse($invoked);
    }

    public function testAllTaggedCacheSkipsEventDispatchSeamWithoutListenersAndEntersItWithListeners(): void
    {
        $put = Double::for(Put::class)->strict();
        $put->expects('execute')->times(2)->returns(true);
        $operations = Double::for(AllTagOperations::class)->strict();
        $operations->expects('put')->times(2)->returns($put);
        $store = Double::for(RedisStore::class)->strict();
        $store->expects('allTagOps')->times(2)->returns($operations);
        $tags = Double::for(AllTagSet::class)->strict();
        $tags->allows('getNamespace')->returns('namespace');
        $tags->allows('tagIds')->returns([]);
        $tags->allows('getNames')->returns(['users']);

        $cache = new class($store, $tags) extends AllTaggedCache {
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
        $cache->setEventDispatcher($this->capturingDispatcher($captured));

        $this->assertTrue($cache->put('second', 'value', 60));
        $this->assertSame(2, $cache->eventCalls);
        $this->assertSame([WritingKey::class, KeyWritten::class], array_map(get_class(...), $captured));

        foreach ($captured as $event) {
            $this->assertSame(['users'], $event->tags);
        }
    }

    public function testPutDispatchesTheRepositoryWriteEventsWithStoreNameAndTags(): void
    {
        $connection = $this->mockConnection();
        $key = hash('xxh128', '_all:tag:users:entries') . ':name';
        $score = now()->timestamp + 60;

        $connection->client()->expects('pipeline')->returns($connection->client());
        $connection->client()->expects('zadd')
            ->with('prefix:_all:tag:users:entries', (float) $score, $key)
            ->returns($connection->client());
        $connection->client()->expects('setex')
            ->with("prefix:{$key}", 60, serialize('John'))
            ->returns($connection->client());
        $connection->client()->expects('exec')->returns([true, 1]);

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

        $connection->client()->expects('get')->with("prefix:{$key}")->returns(null);
        $connection->client()->expects('pipeline')->returns($connection->client());
        $connection->client()->expects('zadd')
            ->with('prefix:_all:tag:users:entries', -1.0, $key)
            ->returns($connection->client());
        $connection->client()->expects('set')
            ->with("prefix:{$key}", serialize('John'))
            ->returns($connection->client());
        $connection->client()->expects('exec')->returns([true, 1]);

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

        $connection->client()->expects('pipeline')->returns($connection->client());
        $connection->client()->expects('zadd')
            ->with('prefix:_all:tag:users:entries', (float) $score, $key)
            ->returns($connection->client());
        $connection->client()->expects('setex')
            ->with("prefix:{$key}", 60, serialize('John'))
            ->returns($connection->client());
        $connection->client()->expects('exec')->returns([false, 1]);

        $captured = [];
        $tagged = (new Repository($this->createStore($connection), ['store' => 'redis']))->tags(['users']);
        $tagged->setEventDispatcher($this->capturingDispatcher($captured));

        $this->assertFalse($tagged->put('name', 'John', 60));
        $this->assertSame([WritingKey::class, KeyWriteFailed::class], array_map(get_class(...), $captured));
        $this->assertSame('redis', $captured[1]->storeName);
        $this->assertSame(['users'], $captured[1]->tags);
    }

    public function testPutDispatchesTheRepositoryFailureEventWhenTheOperationThrows(): void
    {
        $exception = new RuntimeException('write failed');
        $put = Double::for(Put::class)->strict();
        $put->expects('execute')->throws($exception);

        $operations = Double::for(AllTagOperations::class)->strict();
        $operations->expects('put')->returns($put);
        $captured = [];
        $tagged = $this->taggedCacheWithOperations($operations, $captured);

        try {
            $tagged->put('name', 'John', 60);
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
        $put->expects('execute')->throws($cancellation);

        $operations = Double::for(AllTagOperations::class)->strict();
        $operations->expects('put')->returns($put);
        $captured = [];
        $tagged = $this->taggedCacheWithOperations($operations, $captured);

        try {
            $tagged->put('name', 'John', 60);
            $this->fail('Expected the tagged cache write cancellation to be rethrown.');
        } catch (Throwable $exception) {
            $this->assertSame($cancellation, $exception);
        }

        $this->assertSame([WritingKey::class], array_map(get_class(...), $captured));
    }

    public function testPutManyDispatchesRepositoryFailureEventsWhenTheOperationThrows(): void
    {
        $exception = new RuntimeException('batch write failed');
        $values = ['name' => 'John', 'age' => 30];
        $putMany = Double::for(PutMany::class)->strict();
        $putMany->expects('execute')->throws($exception);

        $operations = Double::for(AllTagOperations::class)->strict();
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
        $this->assertSame(['name', 'age'], array_map(static fn (KeyWriteFailed $event): string => $event->key, array_slice($captured, 1)));
        $this->assertSame($exception, $captured[1]->exception);
        $this->assertSame($exception, $captured[2]->exception);
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testPutManyDoesNotDispatchFailureEventsWhenTheOperationIsCanceled(bool $cleanupThrows): void
    {
        $cancellation = new CanceledException('batch write canceled');
        if ($cleanupThrows) {
            $cancellation = new RuntimeException('Cleanup failed.', previous: $cancellation);
        }
        $values = ['name' => 'John', 'age' => 30];
        $putMany = Double::for(PutMany::class)->strict();
        $putMany->expects('execute')->throws($cancellation);

        $operations = Double::for(AllTagOperations::class)->strict();
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
        $store->expects('allTagOps')->never();
        $tags = Double::for(AllTagSet::class)->strict();
        $events = Double::for(Dispatcher::class)->strict();
        $events->expects('hasListeners')->never();
        $events->expects('dispatch')->never();
        $tagged = new AllTaggedCache($store, $tags);
        $tagged->setEventDispatcher($events);

        $this->assertTrue($tagged->putMany([], 60));
    }

    public function testForeverDispatchesTheRepositoryFailureEventWhenTheOperationThrows(): void
    {
        $exception = new RuntimeException('forever write failed');
        $forever = Double::for(Forever::class)->strict();
        $forever->expects('execute')->throws($exception);

        $operations = Double::for(AllTagOperations::class)->strict();
        $operations->expects('forever')->returns($forever);
        $captured = [];
        $tagged = $this->taggedCacheWithOperations($operations, $captured);

        try {
            $tagged->forever('name', 'John');
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
        $forever->expects('execute')->throws($cancellation);

        $operations = Double::for(AllTagOperations::class)->strict();
        $operations->expects('forever')->returns($forever);
        $captured = [];
        $tagged = $this->taggedCacheWithOperations($operations, $captured);

        try {
            $tagged->forever('name', 'John');
            $this->fail('Expected the tagged forever cancellation to be rethrown.');
        } catch (Throwable $exception) {
            $this->assertSame($cancellation, $exception);
        }

        $this->assertSame([WritingKey::class], array_map(get_class(...), $captured));
    }

    public function testPutManyDispatchesTheRepositoryWriteEventsWithStoreNameAndTags(): void
    {
        $connection = $this->mockConnection();
        $namespace = hash('xxh128', '_all:tag:users:entries') . ':';
        $score = now()->timestamp + 60;

        $connection->client()->expects('pipeline')->returns($connection->client());
        $connection->client()->expects('zadd')
            ->with(
                'prefix:_all:tag:users:entries',
                (float) $score,
                $namespace . 'name',
                $score,
                $namespace . 'age'
            )
            ->returns($connection->client());
        $connection->client()->expects('setex')
            ->with("prefix:{$namespace}name", 60, serialize('John'))
            ->returns($connection->client());
        $connection->client()->expects('setex')
            ->with("prefix:{$namespace}age", 60, 30)
            ->returns($connection->client());
        $connection->client()->expects('exec')->returns([true, true, 2]);

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
        $client = new FakeRedisClient(zScanResults: [
            'prefix:_all:tag:users:entries' => [['members' => [], 'iterator' => 0]],
        ]);
        $captured = [];
        $tagged = (new Repository($this->createStoreWithFakeClient($client), ['store' => 'redis']))->tags(['users']);
        $tagged->setEventDispatcher($this->capturingDispatcher($captured));

        $this->assertTrue($tagged->flush());
        $this->assertSame([
            ['key' => 'prefix:_all:tag:users:entries', 'iterator' => null, 'pattern' => '*', 'count' => 1000],
        ], $client->getZScanCalls());
        $this->assertSame([], $client->getEvalShaCalls());
        $this->assertSame([CacheFlushing::class, CacheFlushed::class], array_map(get_class(...), $captured));

        foreach ($captured as $event) {
            $this->assertSame('redis', $event->storeName);
            $this->assertSame(['users'], $event->tags);
        }
    }

    public function testFlushDispatchesFailureEventWithExactOperationException(): void
    {
        $exception = new RuntimeException('flush failed');
        $flush = Double::for(Flush::class)->strict();
        $flush->expects('execute')->throws($exception);

        $operations = Double::for(AllTagOperations::class)->strict();
        $operations->expects('flush')->returns($flush);
        $captured = [];
        $tagged = $this->taggedCacheWithOperations($operations, $captured);

        try {
            $tagged->flush();
            $this->fail('Expected the tagged cache flush exception to be rethrown.');
        } catch (RuntimeException $caught) {
            $this->assertSame($exception, $caught);
        }

        $this->assertSame([CacheFlushing::class, CacheFlushFailed::class], array_map(get_class(...), $captured));
        $this->assertSame($exception, $captured[1]->exception);
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testFlushDoesNotDispatchFailureEventWhenTheOperationIsCanceled(bool $cleanupThrows): void
    {
        $cancellation = new CanceledException;
        if ($cleanupThrows) {
            $cancellation = new RuntimeException('Cleanup failed.', previous: $cancellation);
        }
        $flush = Double::for(Flush::class)->strict();
        $flush->expects('execute')->throws($cancellation);

        $operations = Double::for(AllTagOperations::class)->strict();
        $operations->expects('flush')->returns($flush);
        $captured = [];
        $tagged = $this->taggedCacheWithOperations($operations, $captured);

        try {
            $tagged->flush();
            $this->fail('Expected the tagged cache flush cancellation to be rethrown.');
        } catch (Throwable $caught) {
            $this->assertSame($cancellation, $caught);
        }

        $this->assertSame([CacheFlushing::class], array_map(get_class(...), $captured));
    }

    /**
     * Create an event dispatcher that records dispatched events.
     *
     * @param array<int, object> $captured
     */
    private function capturingDispatcher(array &$captured): Dispatcher
    {
        $dispatcher = Double::for(Dispatcher::class)->strict();
        $dispatcher->allows('hasListeners')->returns(true);
        $dispatcher->allows('dispatch')
            ->resolves(function (object $event) use (&$captured): void {
                $captured[] = $event;
            });

        return $dispatcher;
    }

    private function taggedCacheWithOperations(AllTagOperations $operations, array &$captured): AllTaggedCache
    {
        $store = Double::for(RedisStore::class)->strict();
        $store->expects('allTagOps')->returns($operations);

        $tags = Double::for(AllTagSet::class)->strict();
        $tags->allows('getNames')->returns(['users']);
        $tags->allows('getNamespace')->returns('users');
        $tags->allows('tagIds')->returns(['users-id']);

        $cache = new AllTaggedCache($store, $tags);
        $store->expects('tags')->with(['users'])->returns($cache);

        $tagged = (new Repository($store, ['store' => 'redis']))->tags(['users']);
        $tagged->setEventDispatcher($this->capturingDispatcher($captured));

        return $tagged;
    }
}

enum AllTaggedCacheTestKey: int
{
    case Profile = 0;
    case Settings = 1;
}
