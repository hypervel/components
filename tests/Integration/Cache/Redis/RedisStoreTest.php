<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Cache\Redis;

use BadMethodCallException;
use DateTime;
use Hypervel\Cache\Events\ForgettingKey;
use Hypervel\Cache\Events\KeyForgetFailed;
use Hypervel\Cache\Events\KeyForgotten;
use Hypervel\Cache\Redis\Operations\AllTag\Flush;
use Hypervel\Cache\Redis\Operations\AllTag\GetEntries;
use Hypervel\Cache\RedisStore;
use Hypervel\Cache\Repository;
use Hypervel\Contracts\Redis\Factory as RedisFactory;
use Hypervel\Foundation\Testing\Concerns\InteractsWithRedis;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Facades\Cache;
use Hypervel\Support\Facades\Event;
use Hypervel\Support\LazyCollection;
use Hypervel\Support\Sleep;
use Hypervel\Support\Str;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Testbench\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use LogicException;
use PHPUnit\Framework\Attributes\TestWith;
use Redis;
use RuntimeException;
use UnitEnum;

class RedisStoreTest extends TestCase
{
    use InteractsWithRedis;
    use VerifiesDoubles;

    public function testCacheTtl(): void
    {
        $store = Cache::store('redis');
        $store->clear();

        while ((microtime(true) - time()) > 0.5 && (microtime(true) - time()) < 0.6);

        $store->put('hello', 'world', 1);
        $putAt = microtime(true);

        Sleep::for(600)->milliseconds();
        $this->assertTrue((microtime(true) - $putAt) < 1);
        $this->assertSame('world', $store->get('hello'));

        // Although this key expires after exactly 1 second, Redis has a
        // 0-1 millisecond error rate on expiring keys (as of Redis 2.6) so
        // for a non-flakey test we need to account for the millisecond.
        // see: https://redis.io/commands/expire/
        while ((microtime(true) - $putAt) < 1.001);

        $this->assertNull($store->get('hello'));
    }

    public function testItCanStoreInfinite(): void
    {
        Cache::store('redis')->clear();

        $result = Cache::store('redis')->put('foo', INF);
        $this->assertTrue($result);
        $this->assertSame(INF, Cache::store('redis')->get('foo'));

        $result = Cache::store('redis')->put('bar', -INF);
        $this->assertTrue($result);
        $this->assertSame(-INF, Cache::store('redis')->get('bar'));
    }

    public function testItCanStoreNan(): void
    {
        Cache::store('redis')->clear();

        $result = Cache::store('redis')->put('foo', NAN);
        $this->assertTrue($result);
        $this->assertNan(Cache::store('redis')->get('foo'));
    }

    public function testItCanExpireWithZeroTTL(): void
    {
        Cache::store('redis')->clear();

        $result = Cache::store('redis')->put('foo', 10, 10);
        $this->assertTrue($result);

        $result = Cache::store('redis')->put('foo', 10, 0);
        $this->assertTrue($result);

        $value = Cache::store('redis')->get('foo');
        $this->assertNull($value);
    }

    #[TestWith(['hypervel_cache_'])]
    #[TestWith(['hypervel-cache-'])]
    public function testTagsCanBeAccessed(string $cachePrefix): void
    {
        config(['cache.prefix' => $cachePrefix]);

        Cache::store('redis')->clear();

        Cache::store('redis')->tags(['people', 'author'])->put('name', 'Sally', 5);
        Cache::store('redis')->tags(['people', 'author'])->put('age', 30, 5);

        $this->assertSame('Sally', Cache::store('redis')->tags(['people', 'author'])->get('name'));
        $this->assertEquals(30, Cache::store('redis')->tags(['people', 'author'])->get('age'));

        Cache::store('redis')->tags(['people', 'author'])->flush();

        $keyCount = Cache::store('redis')->connection()->keys('*');
        $this->assertCount(0, $keyCount);
    }

    public function testTagEntriesCanBeStoredForever(): void
    {
        Cache::store('redis')->clear();

        Cache::store('redis')->tags(['people', 'author'])->forever('name', 'Sally');
        Cache::store('redis')->tags(['people', 'author'])->forever('age', 30);

        $this->assertSame('Sally', Cache::store('redis')->tags(['people', 'author'])->get('name'));
        $this->assertEquals(30, Cache::store('redis')->tags(['people', 'author'])->get('age'));

        Cache::store('redis')->tags(['people', 'author'])->flush();

        $keyCount = Cache::store('redis')->connection()->keys('*');
        $this->assertCount(0, $keyCount);
    }

    public function testTagEntriesCanBeIncremented(): void
    {
        Cache::store('redis')->clear();

        Cache::store('redis')->tags(['votes'])->put('person-1', 0, 5);
        Cache::store('redis')->tags(['votes'])->increment('person-1');
        Cache::store('redis')->tags(['votes'])->increment('person-1');

        $this->assertEquals(2, Cache::store('redis')->tags(['votes'])->get('person-1'));

        Cache::store('redis')->tags(['votes'])->decrement('person-1');
        Cache::store('redis')->tags(['votes'])->decrement('person-1');

        $this->assertEquals(0, Cache::store('redis')->tags(['votes'])->get('person-1'));
    }

    public function testTagEntriesCanBeDecrementedUsingEnumKeys(): void
    {
        Cache::store('redis')->clear();

        Cache::store('redis')->tags(['votes'])->put(RedisTaggedCacheTestKey::Person1, 2, 5);
        Cache::store('redis')->tags(['votes'])->decrement(RedisTaggedCacheTestKey::Person1);

        $this->assertEquals(1, Cache::store('redis')->tags(['votes'])->get(RedisTaggedCacheTestKey::Person1));

        Cache::store('redis')->tags(['votes'])->flush();

        $this->assertNull(Cache::store('redis')->tags(['votes'])->get(RedisTaggedCacheTestKey::Person1));
    }

    public function testIncrementedTagEntriesProperlyTurnStale(): void
    {
        Cache::store('redis')->clear();

        Cache::store('redis')->tags(['votes'])->add('person-1', 0, $seconds = 1);
        Cache::store('redis')->tags(['votes'])->increment('person-1');
        Cache::store('redis')->tags(['votes'])->increment('person-1');

        sleep(2);

        Cache::store('redis')->tags(['votes'])->flushStale();

        $keyCount = Cache::store('redis')->connection()->keys('*');
        $this->assertCount(0, $keyCount);
    }

    public function testPastTtlTagEntriesAreNotAdded(): void
    {
        Cache::store('redis')->clear();

        Cache::store('redis')->tags(['votes'])->add('person-1', 0, new DateTime('yesterday'));

        $value = Cache::store('redis')->tags(['votes'])->get('person-1');
        $this->assertNull($value);

        $keyCount = Cache::store('redis')->connection()->keys('*');
        $this->assertCount(0, $keyCount);
    }

    public function testPutPastTtlTagEntriesProperlyTurnStale(): void
    {
        Cache::store('redis')->clear();

        Cache::store('redis')->tags(['votes'])->put('person-1', 0, new DateTime('yesterday'));
        Cache::store('redis')->tags(['votes'])->flushStale();

        $keyCount = Cache::store('redis')->connection()->keys('*');
        $this->assertCount(0, $keyCount);
    }

    public function testTagsCanBeFlushedBySingleKey(): void
    {
        Cache::store('redis')->clear();

        Cache::store('redis')->tags(['people', 'author'])->put('person-1', 'Sally', 5);
        Cache::store('redis')->tags(['people', 'artist'])->put('person-2', 'John', 5);

        Cache::store('redis')->tags(['artist'])->flush();

        $this->assertSame('Sally', Cache::store('redis')->tags(['people', 'author'])->get('person-1'));
        $this->assertNull(Cache::store('redis')->tags(['people', 'artist'])->get('person-2'));

        $keyCount = Cache::store('redis')->connection()->keys('*');
        $this->assertCount(3, $keyCount); // Sets for people, authors, and actual entry for Sally
    }

    public function testStaleEntriesCanBeFlushed(): void
    {
        Cache::store('redis')->clear();

        Cache::store('redis')->tags(['people', 'author'])->put('person-1', 'Sally', 1);
        Cache::store('redis')->tags(['people', 'artist'])->put('person-2', 'John', 1);

        sleep(2);

        // Add a non-stale entry to people...
        Cache::store('redis')->tags(['people', 'author'])->put('person-3', 'Jennifer', 5);

        Cache::store('redis')->tags(['people'])->flushStale();

        $keyCount = Cache::store('redis')->connection()->keys('*');
        $this->assertCount(4, $keyCount); // Sets for people, authors, and artists + individual entry for Jennifer
    }

    public function testTouchedTagEntriesAreNotConsideredStale(): void
    {
        Cache::store('redis')->clear();

        Cache::store('redis')->tags(['people'])->put('person-1', 'Sally', 1);
        $tagged = Cache::store('redis')->tags(['people']);
        $before = time();
        $this->assertTrue($tagged->touch('person-1', 30));
        $after = time();

        $store = Cache::store('redis')->getStore();
        $score = (int) $store->connection()->zScore(
            $store->getPrefix() . $tagged->getTags()->tagIds()[0],
            $tagged->taggedItemKey('person-1'),
        );
        $this->assertGreaterThanOrEqual($before + 30, $score);
        $this->assertLessThanOrEqual($after + 31, $score);

        sleep(2);

        Cache::store('redis')->tags(['people'])->flushStale();

        $this->assertSame('Sally', Cache::store('redis')->tags(['people'])->get('person-1'));

        Cache::store('redis')->tags(['people'])->flush();

        $this->assertNull(Cache::store('redis')->tags(['people'])->get('person-1'));

        $keyCount = Cache::store('redis')->connection()->keys('*');
        $this->assertCount(0, $keyCount);
    }

    public function testMultipleItemsCanBeSetAndRetrieved(): void
    {
        $store = Cache::store('redis');
        $result = $store->put('foo', 'bar', 10);
        $resultMany = $store->putMany([
            'fizz' => 'buz',
            'quz' => 'baz',
        ], 10);
        $this->assertTrue($result);
        $this->assertTrue($resultMany);
        $this->assertEquals([
            'foo' => 'bar',
            'fizz' => 'buz',
            'quz' => 'baz',
            'norf' => null,
        ], $store->many(['foo', 'fizz', 'quz', 'norf']));

        $this->assertSame([], $store->many([]));
    }

    // REMOVED: testPutManyCallsPutWhenClustered - Hypervel's RedisStore delegates putMany to the
    // PutMany operation class which has its own cluster fallback. This behavior is tested in
    // tests/Cache/Redis/Operations/PutManyTest.php (cluster mode tests).

    public function testIncrementWithSerializationEnabled(): void
    {
        if (! defined('Redis::OPT_PACK_IGNORE_NUMBERS')) {
            $this->markTestSkipped('PhpRedis does not support OPT_PACK_IGNORE_NUMBERS.');
        }

        $connection = $this->createRedisConnectionWithOptions('cache_serialized', [
            'serializer' => Redis::SERIALIZER_PHP,
            'pack_ignore_numbers' => true,
        ]);
        config(['cache.stores.redis.connection' => $connection]);

        $store = Cache::store('redis');
        $store->flush();
        $store->add('foo', 1, 10);
        $this->assertSame(1, $store->get('foo'));

        $store->increment('foo');
        $this->assertSame(2, $store->get('foo'));
    }

    public function testTagsCanBeFlushedWithLargeNumberOfKeys(): void
    {
        Cache::store('redis')->clear();

        $tags = ['large-test-' . time()];

        for ($i = 1; $i <= 5000; ++$i) {
            Cache::store('redis')->tags($tags)->put("key:{$i}", "value:{$i}", 300);
        }

        $this->assertSame('value:1', Cache::store('redis')->tags($tags)->get('key:1'));
        $this->assertSame('value:2500', Cache::store('redis')->tags($tags)->get('key:2500'));
        $this->assertSame('value:5000', Cache::store('redis')->tags($tags)->get('key:5000'));

        Cache::store('redis')->tags($tags)->flush();

        $this->assertNull(Cache::store('redis')->tags($tags)->get('key:1'));
        $this->assertNull(Cache::store('redis')->tags($tags)->get('key:2500'));
        $this->assertNull(Cache::store('redis')->tags($tags)->get('key:5000'));

        $keyCount = Cache::store('redis')->connection()->keys('*');
        $this->assertCount(0, $keyCount);
    }

    public function testItStoresAndRetrievesValues(): void
    {
        $store = Cache::store('redis');
        $store->clear();

        $this->assertNull($store->get('foo'));

        $this->assertTrue($store->put('foo', 'bar', 60));
        $this->assertTrue($store->put('number', 5, 60));
        $this->assertTrue($store->put('float', 1.5, 60));

        $this->assertSame('bar', $store->get('foo'));
        $this->assertEquals(5, $store->get('number'));
        $this->assertEquals(1.5, $store->get('float'));
    }

    public function testItStoresAndRetrievesManyValues(): void
    {
        $store = Cache::store('redis');
        $store->clear();

        $this->assertTrue($store->putMany(['foo' => 'bar', 'fizz' => 'buzz', 'norf' => 'quz'], 60));

        $this->assertSame(
            ['foo' => 'bar', 'fizz' => 'buzz', 'norf' => 'quz', 'missing' => null],
            $store->many(['foo', 'fizz', 'norf', 'missing'])
        );

        $ttl = $store->connection()->ttl($store->getPrefix() . 'fizz');
        $this->assertGreaterThan(0, $ttl);
        $this->assertLessThanOrEqual(60, $ttl);
    }

    public function testItIncrementsAndDecrementsValues(): void
    {
        $store = Cache::store('redis');
        $store->clear();

        $store->put('count', 1, 60);

        $this->assertSame(6, $store->increment('count', 5));
        $this->assertEquals(6, $store->get('count'));
        $this->assertSame(4, $store->decrement('count', 2));
        $this->assertEquals(4, $store->get('count'));
    }

    public function testItStoresValuesForeverAndCanTouchThem(): void
    {
        $store = Cache::store('redis');
        $store->clear();
        $connection = $store->connection();

        $this->assertTrue($store->forever('forever', 'value'));
        $this->assertSame('value', $store->get('forever'));
        $this->assertSame(-1, $connection->ttl($store->getPrefix() . 'forever'));

        $store->put('touched', 'value', 10);

        $this->assertTrue($store->touch('touched', 100));
        $this->assertGreaterThan(10, $connection->ttl($store->getPrefix() . 'touched'));
        $this->assertSame('value', $store->get('touched'));
    }

    public function testFlushStaleTagsRemovesExpiredEntriesAndStopsScanning(): void
    {
        $store = Cache::store('redis');

        $store->clear();

        // Backdate membership scores while leaving Redis values alive, so pruning
        // must remove expired scores rather than relying on orphan cleanup.
        CarbonImmutable::setTestNow(CarbonImmutable::now()->subMinutes(5));

        try {
            $store->tags(['foo'])->put('expired', 'value', 60);
        } finally {
            CarbonImmutable::setTestNow();
        }

        $store->tags(['foo'])->put('fresh', 'value', 3600);

        $tagSet = $store->getPrefix() . '_all:tag:foo:entries';

        $this->assertSame(2, $store->connection()->zcard($tagSet));

        // The scan has to recognize it is back at its starting cursor, or this never returns...
        $store->flushStaleTags();

        $this->assertSame(1, $store->connection()->zcard($tagSet));
        $this->assertSame('value', $store->tags(['foo'])->get('fresh'));
    }

    #[WithConfig('database.redis.options.prefix', 'app_')]
    #[WithConfig('database.redis.options.scan', Redis::SCAN_PREFIX)]
    public function testFlushStaleTagsDoesNotDoublePrefixTheMatchPatternWithScanPrefix(): void
    {
        $store = Cache::store('redis');
        $store->clear();

        CarbonImmutable::setTestNow(CarbonImmutable::now()->subMinutes(5));

        try {
            $store->tags(['foo'])->put('expired', 'value', 60);
        } finally {
            CarbonImmutable::setTestNow();
        }

        $tagSet = $store->getPrefix() . '_all:tag:foo:entries';
        $connection = $store->connection();

        $this->assertSame(1, $connection->zcard($tagSet));

        $store->flushStaleTags();

        $this->assertSame(0, $connection->zcard($tagSet));
    }

    public function testItForgetsAndFlushesValues(): void
    {
        $store = Cache::store('redis');
        $store->clear();

        $store->put('foo', 'bar', 60);
        $store->put('fizz', 'buzz', 60);

        $this->assertTrue($store->forget('foo'));
        $this->assertNull($store->get('foo'));
        $this->assertSame('buzz', $store->get('fizz'));

        $this->assertTrue($store->flush());
        $this->assertNull($store->get('fizz'));
    }

    public function testItStoresManyValuesForeverInOnePipeline(): void
    {
        $store = Cache::store('redis');
        $store->clear();

        // REMOVED: Upstream records the commands run outside a pipeline. Redis command events fire only for RedisProxy
        // calls, not inside the store's held connection, so ForeverTest asserts the single pipeline instead.
        $this->assertTrue($store->putMany(['foo' => 'bar', 'number' => 5, 'array' => ['a' => 1]]));

        $this->assertEquals(['foo' => 'bar', 'number' => 5, 'array' => ['a' => 1]], $store->many(['foo', 'number', 'array']));
        $this->assertSame(-1, $store->connection()->ttl($store->getPrefix() . 'foo'));
        $this->assertSame(6, $store->increment('number'));
    }

    // REMOVED: testItStoresManyValuesWithTtlInOneTransactionPipeline - putMany() with a TTL is one Lua call (PutManyTest),
    // and testItStoresAndRetrievesManyValues checks its values and TTL.

    public function testItDeletesManyValuesInOnePipeline(): void
    {
        $store = Cache::store('redis');
        $store->clear();

        $store->putMany(['foo' => 'bar', 'fizz' => 'buzz', 'norf' => 'quz']);

        $keys = (function () {
            yield 'foo';
            yield 'fizz';
            yield 'foo';
        })();

        // The repeated key no longer exists when it is deleted the second time...
        $this->assertFalse($store->deleteMultiple($keys));

        $this->assertSame(['foo' => null, 'fizz' => null, 'norf' => 'quz'], $store->many(['foo', 'fizz', 'norf']));
        $this->assertTrue($store->deleteMultiple(['norf']));
    }

    public function testTaggedValuesStoredManyForeverCanBeFlushed(): void
    {
        $store = Cache::store('redis');
        $store->clear();

        $store->tags(['people'])->putMany(['person-1' => 'Sally', 'person-2' => 'John']);
        $this->assertSame(['person-1' => 'Sally', 'person-2' => 'John'], $store->tags(['people'])->many(['person-1', 'person-2']));

        $store->tags(['people'])->flush();

        $this->assertSame(['person-1' => null, 'person-2' => null], $store->tags(['people'])->many(['person-1', 'person-2']));
    }

    // REMOVED: testItWritesAndDeletesManyValuesAfterTheConnectionWasDropped - Hypervel does not replay Redis commands
    // after a lost connection, because a replayed DEL can remove a value written again in between. The pooled
    // connection is invalidated and reconnects when it is next used.

    // REMOVED: testAFailedTransactionLeavesTheConnectionReadingItsOwnReplies - putMany() with a TTL is one Lua call,
    // not a pipelined MULTI, so a failed write leaves no transaction replies unread.

    public function testDeletingManyMemoizedTaggedValuesDeletesThem(): void
    {
        Cache::store('redis')->clear();

        $memoized = Cache::memo('redis')->tags(['people']);
        $memoized->putMany(['person-1' => 'Sally', 'person-2' => 'John'], 60);
        $this->assertSame('Sally', $memoized->get('person-1'));

        $this->assertTrue($memoized->deleteMultiple(['person-1', 'person-2']));

        $this->assertNull($memoized->get('person-1'));
        $this->assertNull(Cache::store('redis')->tags(['people'])->get('person-1'));
    }

    public function testRepositoriesThatOverrideForeverOrForgetStillSeeEachKey(): void
    {
        $repository = new class(Cache::store('redis')->getStore()) extends Repository {
            public array $calls = [];

            public function forever(UnitEnum|string $key, mixed $value): bool
            {
                $this->calls[] = "forever {$key}";

                return parent::forever($key, $value);
            }

            public function forget(array|UnitEnum|string $key): bool
            {
                $this->calls[] = 'forget ' . (is_array($key) ? implode(',', $key) : $key);

                return parent::forget($key);
            }
        };

        $repository->putMany(['foo' => 'bar', 'fizz' => 'buzz']);
        $repository->deleteMultiple(['foo', 'fizz']);

        $this->assertSame(['forever foo', 'forever fizz', 'forget foo', 'forget fizz'], $repository->calls);
    }

    public function testStoresThatOverrideForeverOrForgetStillSeeEachKey(): void
    {
        $store = new class($this->app->make(RedisFactory::class)) extends RedisStore {
            public array $calls = [];

            public function forever(string $key, mixed $value): bool
            {
                $this->calls[] = "forever {$key}";

                return parent::forever($key, $value);
            }

            public function forget(string $key): bool
            {
                $this->calls[] = "forget {$key}";

                return parent::forget($key);
            }
        };
        $repository = new Repository($store);

        $this->assertTrue($repository->putMany(['foo' => 'bar', 'fizz' => 'buzz']));
        $this->assertTrue($repository->deleteMultiple(['foo', 'fizz']));

        $this->assertSame(['forever foo', 'forever fizz', 'forget foo', 'forget fizz'], $store->calls);
    }

    public function testObservedDeletesDispatchEachKeysEventsInOrder(): void
    {
        $store = Cache::store('redis');
        $store->clear();
        $store->put('foo', 'bar', 60);

        $events = [];
        Event::listen(
            [ForgettingKey::class, KeyForgotten::class, KeyForgetFailed::class],
            function (ForgettingKey|KeyForgotten|KeyForgetFailed $event) use (&$events): void {
                $events[] = [$event::class, $event->key];
            },
        );

        $this->assertFalse($store->deleteMultiple(['foo', 'missing']));

        $this->assertSame([
            [ForgettingKey::class, 'foo'],
            [KeyForgotten::class, 'foo'],
            [ForgettingKey::class, 'missing'],
            [KeyForgetFailed::class, 'missing'],
        ], $events);
        $this->assertNull($store->get('foo'));
    }

    public function testCacheOperationsRejectATransactionOpenOnTheCacheConnection(): void
    {
        $store = Cache::store('redis');
        $store->clear();

        // The rejection is caught inside the callback so EXEC still runs anything that was queued.
        $store->getStore()->connection()->transaction(function () use ($store): void {
            try {
                $store->put('foo', 'bar', 60);
                $this->fail('The cache write was not rejected.');
            } catch (LogicException $exception) {
                $this->assertStringContainsString('queuing a pipeline or transaction', $exception->getMessage());
            }
        });

        $this->assertNull($store->get('foo'));
    }

    #[WithConfig('cache.stores.failover.stores', ['redis', 'array'])]
    public function testACounterRejectsAPipelineOpenOnTheCacheConnectionBeforeQueuingIt(): void
    {
        if ($this->usingRedisCluster()) {
            $this->markTestSkipped('Redis Cluster connections do not support pipelines.');
        }

        $redis = Cache::store('redis');
        $redis->clear();
        $redis->forever('count', 10);
        Cache::store('array')->forever('count', 40);

        // The failover store catches Redis' failure, so a counter queued before failing would still run at EXEC.
        $results = $redis->getStore()->connection()->pipeline(function (Redis $pipeline) use (&$value): void {
            $pipeline->echo('before');
            $value = Cache::store('failover')->increment('count');
            $pipeline->echo('after');
        });

        $this->assertSame(41, $value);
        $this->assertSame(['before', 'after'], $results);
        $this->assertEquals(10, $redis->get('count'));
    }

    public function testFlushingManyTagsOnlyPassesEachEntrysOwnTagToTheScript(): void
    {
        // Hypervel runs flush scripts on the raw connection, which dispatches no command events,
        // so the per-script arguments are asserted in Cache\Redis\Operations\AllTag\FlushTest.
        $store = Cache::store('redis');
        $connection = $store->connection();

        $store->clear();

        $prefix = $store->getPrefix();

        $store->tags(['people'])->put('person-1', 'Sally', 300);
        $store->tags(['people', 'artist'])->put('person-2', 'John', 300);
        $store->tags(['artist'])->forever('person-3', 'Taylor');
        $store->tags(['other'])->put('person-4', 'Abigail', 300);

        $store->tags(['people', 'artist', 'empty-1', 'empty-2'])->flush();

        $this->assertNull($store->tags(['people'])->get('person-1'));
        $this->assertNull($store->tags(['people', 'artist'])->get('person-2'));
        $this->assertNull($store->tags(['artist'])->get('person-3'));
        $this->assertSame('Abigail', $store->tags(['other'])->get('person-4'));
        $this->assertSame(0, $connection->zcard($prefix . '_all:tag:people:entries'));
        $this->assertSame(0, $connection->zcard($prefix . '_all:tag:artist:entries'));
        $this->assertSame(1, $connection->zcard($prefix . '_all:tag:other:entries'));
    }

    public function testFlushingTagsWhileASharedKeyIsRewrittenLeavesNoLiveValueUnindexed(): void
    {
        $store = Cache::store('redis');
        $connection = $store->connection();

        $store->clear();

        $prefix = $store->getPrefix();

        $store->tags(['people', 'artist'])->forever('person', 'Sally');

        // Hypervel's flush scripts dispatch no command events, so the rewrite happens when the
        // second tag's entries are requested, after the first tag's script has run.
        $context = $store->getStore()->getContext();
        $scanner = new GetEntries($context);
        $requests = 0;
        $entries = Double::for(GetEntries::class)->strict();
        $entries->expects('execute')
            ->times(2)
            ->resolves(function (array $tagIds) use ($scanner, $store, &$requests): LazyCollection {
                if (++$requests === 2) {
                    $store->tags(['people', 'artist'])->forever('person', 'John');
                }

                return $scanner->execute($tagIds);
            });

        (new Flush($context, $entries))->execute($store->tags(['people', 'artist'])->getTags()->tagIds());

        $itemKey = $store->tags(['people', 'artist'])->taggedItemKey('person');

        if ($store->tags(['people', 'artist'])->get('person') !== null) {
            $this->assertContains($itemKey, $connection->zrange($prefix . '_all:tag:people:entries', 0, -1));
            $this->assertContains($itemKey, $connection->zrange($prefix . '_all:tag:artist:entries', 0, -1));
        }

        $store->tags(['people'])->flush();
        $store->tags(['artist'])->flush();

        $this->assertNull($store->tags(['people', 'artist'])->get('person'));
        $this->assertSame(0, $connection->zcard($prefix . '_all:tag:people:entries'));
        $this->assertSame(0, $connection->zcard($prefix . '_all:tag:artist:entries'));
    }

    public function testLocksCanBeFlushed(): void
    {
        $store = $this->getStoreWithSeparateLockStorage();

        $this->assertTrue($store->lock('lock-1', 60)->acquire());
        $this->assertTrue($store->lock('lock-2', 60)->acquire());
        $this->assertTrue($store->lock('lock-3', 60)->acquire());

        $this->assertTrue($store->flushLocks());

        $this->assertTrue($store->lock('lock-1', 60)->acquire());
        $this->assertTrue($store->lock('lock-2', 60)->acquire());
        $this->assertTrue($store->lock('lock-3', 60)->acquire());
    }

    public function testFlushLocksDoesNotAffectNonLockKeys(): void
    {
        $store = $this->getStoreWithSeparateLockStorage();
        $key = 'lock-flush-' . Str::random(16);

        try {
            $this->assertTrue($store->put($key, 'bar', 60));
            $this->assertTrue($store->lock('lock-1', 60)->acquire());

            $this->assertTrue($store->flushLocks());

            $this->assertSame('bar', $store->get($key));
        } finally {
            $store->forget($key);
        }
    }

    public function testLockOperationsRejectATransactionOpenOnTheLockConnection(): void
    {
        $store = $this->getStoreWithSeparateLockStorage();
        $this->assertTrue($store->lock('held', 60)->acquire());

        // Creating a lock or funnel runs no command, so it may happen before the transaction.
        $lock = $store->lock('foo');
        $funnel = $store->funnel('bar')->limit(1)->releaseAfter(60)->block(0);

        // A lock on its own idle connection is unaffected by a transaction on the cache connection.
        $store->getStore()->connection()->transaction(function () use ($store): void {
            $this->assertTrue($store->lock('baz', 60)->get());
        });

        foreach (['lock' => $lock->get(...), 'funnel' => $funnel->acquire(...), 'lock flush' => $store->flushLocks(...)] as $name => $operation) {
            // The rejection is caught inside the callback so EXEC still runs anything that was queued.
            $store->getStore()->lockConnection()->transaction(function () use ($name, $operation): void {
                try {
                    $operation();
                    $this->fail("The {$name} was not rejected.");
                } catch (LogicException) {
                }
            });
        }

        // Nothing was queued: the forever lock and the funnel slot are free, and the held lock remains.
        $this->assertTrue($store->lock('foo')->get());
        $funnel->acquire()->release();
        $this->assertFalse($store->lock('held', 60)->get());
    }

    public function testHasSeparateLockStoreReturnsTrueWhenLockConnectionDiffers(): void
    {
        $store = $this->getStoreWithSeparateLockStorage();

        $this->assertTrue($store->hasSeparateLockStore());
    }

    public function testHasSeparateLockStoreReturnsFalseWhenLockConnectionIsSame(): void
    {
        $store = Cache::store('redis');
        $store->setConnection('default');
        $store->setLockConnection('default');

        $this->assertFalse($store->hasSeparateLockStore());
    }

    public function testRepositoryFlushLocksThrowsExceptionWhenLockConnectionIsSame(): void
    {
        $repository = Cache::store('redis');
        /** @var RedisStore $store */
        $store = $repository->getStore();
        $store->setConnection('default');
        $store->setLockConnection('default');

        $this->expectException(BadMethodCallException::class);

        $repository->flushLocks();
    }

    public function testStoreFlushLocksThrowsExceptionWhenLockConnectionIsSame(): void
    {
        /** @var RedisStore $store */
        $store = Cache::store('redis')->getStore();
        $store->setConnection('default');
        $store->setLockConnection('default');

        $this->expectException(RuntimeException::class);

        $store->flushLocks();
    }

    /**
     * Get a store with cache entries and locks in separate Redis databases.
     */
    protected function getStoreWithSeparateLockStorage(): Repository
    {
        if ($this->usingRedisCluster()) {
            $this->markTestSkipped('Separate lock storage requires two Redis databases; the test Cluster provides only database zero.');
        }

        // Only the worker-owned database may be flushed. The shared secondary
        // database holds cache entries, which the test removes individually.
        $connection = config('database.redis.default');
        $connection['database'] = $this->getSecondaryRedisDb();

        config([
            'database.redis.lock_flush_cache' => $connection,
            'cache.stores.redis.connection' => 'lock_flush_cache',
            'cache.stores.redis.lock_connection' => 'default',
        ]);

        return Cache::store('redis');
    }
}

enum RedisTaggedCacheTestKey: string
{
    case Person1 = 'person-1';
}
