<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache;

use __PHP_Incomplete_Class;
use Hypervel\Cache\Exceptions\ValueTooLargeForColumnException;
use Hypervel\Cache\NullSentinel;
use Hypervel\Cache\Repository;
use Hypervel\Cache\SerializableClassPolicy;
use Hypervel\Cache\SwooleStore;
use Hypervel\Cache\SwooleTableManager;
use Hypervel\Cache\SwooleTableState;
use Hypervel\Contracts\Cache\CanFlushLocks;
use Hypervel\Contracts\Cache\LockProvider;
use Hypervel\Contracts\Config\Repository as ConfigRepository;
use Hypervel\Contracts\Container\Container;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Str;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use Mockery as m;
use ReflectionMethod;
use stdClass;
use TypeError;

class CacheSwooleStoreTest extends TestCase
{
    public function testCanRetrieveItemsFromStore(): void
    {
        $state = $this->createState();
        $store = $this->createStore($state);

        $this->setLogicalRow($state, $store, 'foo', 'bar', time() + 100);

        $this->assertEquals('bar', $store->get('foo'));
    }

    public function testMissingItemsReturnNull(): void
    {
        $store = $this->createStore();

        $this->assertNull($store->get('foo'));
    }

    public function testMissingSwooleTableConfigThrowsTableNotDefinedException(): void
    {
        $config = m::mock(ConfigRepository::class);
        $config->shouldReceive('get')
            ->once()
            ->with('cache.swoole_tables.missing')
            ->andReturn(null);

        $container = m::mock(Container::class);
        $container->shouldReceive('make')->once()->with('config')->andReturn($config);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Swoole table [missing] is not defined.');

        (new SwooleTableManager($container))->get('missing');
    }

    public function testSwooleTableRejectsStringValuesLargerThanColumnSize(): void
    {
        $table = $this->createState(bytes: 8)->table();

        $this->expectException(ValueTooLargeForColumnException::class);
        $this->expectExceptionMessage('Should be less than 8 characters but got 9 characters.');

        $table->set('foo', ['value' => '123456789']);
    }

    public function testExpiredItemsReturnNull(): void
    {
        $state = $this->createState();
        $store = $this->createStore($state);

        $this->setLogicalRow($state, $store, 'foo', 'bar', time() - 100);

        $this->assertNull($store->get('foo'));
    }

    public function testGetMethodCanResolvePendingInterval(): void
    {
        $store = $this->createStore();

        $store->interval('foo', fn () => 'bar', 1);

        $this->assertEquals('bar', $store->get('foo'));
    }

    public function testManyMethodCanReturnManyValues(): void
    {
        $state = $this->createState();
        $store = $this->createStore($state);

        $this->setLogicalRow($state, $store, 'foo', 'bar', time() + 100);
        $this->setLogicalRow($state, $store, 'bar', 'baz', time() + 100);

        $this->assertEquals(['foo' => 'bar', 'bar' => 'baz'], $store->many(['foo', 'bar']));
    }

    public function testPutStoresValueInTable(): void
    {
        $store = $this->createStore();

        $store->put('foo', 'bar', 5);

        $this->assertEquals('bar', $store->get('foo'));
    }

    public function testPutRejectsClassesDuringUnserializationWhenConfigured(): void
    {
        $store = new SwooleStore(
            $this->createState(),
            0.05,
            SwooleStore::EVICTION_POLICY_TTL,
            0.05,
            serializableClassPolicy: new SerializableClassPolicy(static fn (): false => false),
        );

        $store->put('foo', new stdClass, 5);

        $this->assertInstanceOf(__PHP_Incomplete_Class::class, $store->get('foo'));
    }

    public function testPutAllowsConfiguredClassesDuringUnserialization(): void
    {
        $store = new SwooleStore(
            $this->createState(),
            0.05,
            SwooleStore::EVICTION_POLICY_TTL,
            0.05,
            serializableClassPolicy: new SerializableClassPolicy(static fn (): array => [stdClass::class]),
        );

        $store->put('foo', new stdClass, 5);

        $this->assertInstanceOf(stdClass::class, $store->get('foo'));
    }

    public function testPutAllowsClassesByDefaultWhenConstructedDirectly(): void
    {
        $store = $this->createStore();

        $store->put('foo', new stdClass, 5);

        $this->assertInstanceOf(stdClass::class, $store->get('foo'));
    }

    public function testNullSentinelRoundTripsThroughSwooleStore(): void
    {
        $store = $this->createStore();
        $repo = new Repository($store);

        $repo->rememberNullable('k', 60, fn () => null);

        $this->assertSame(NullSentinel::VALUE, $store->get('k'));
        $this->assertNull($repo->get('k'));

        $invoked = false;
        $result = $repo->rememberNullable('k', 60, function () use (&$invoked) {
            $invoked = true;

            return 'should-not-run';
        });

        $this->assertNull($result);
        $this->assertFalse($invoked);
    }

    public function testPutManyStoresValueInTable(): void
    {
        $store = $this->createStore();

        $this->assertTrue($store->putMany(['foo' => 'bar', 'bar' => 'baz'], 5));

        $this->assertEquals('bar', $store->get('foo'));
        $this->assertEquals('baz', $store->get('bar'));
    }

    public function testPutManyReturnsTrueForEmptyInput(): void
    {
        $store = $this->createStore();

        $this->assertTrue($store->putMany([], 5));
    }

    public function testPutManyReturnsFalseForPartialFailureAndAttemptsEveryValue(): void
    {
        $store = new SwooleStorePutManyProbe($this->createState(), ['fail']);

        $this->assertFalse($store->putMany([
            1 => 'one',
            'fail' => 'two',
            'after' => 'three',
        ], 5));
        $this->assertSame(['1', 'fail', 'after'], $store->attempts);
    }

    public function testAddOverwritesExpiredPhysicalRow(): void
    {
        $state = $this->createState();
        $store = $this->createStore($state);

        $this->setLogicalRow($state, $store, 'foo', 'old', time() - 100);

        $this->assertTrue($store->add('foo', 'new', 5));
        $this->assertSame('new', $store->get('foo'));
    }

    public function testAddPreservesLiveRow(): void
    {
        $state = $this->createState();
        $store = $this->createStore($state);

        $this->setLogicalRow($state, $store, 'foo', 'bar', time() + 100);

        $this->assertFalse($store->add('foo', 'baz', 5));
        $this->assertSame('bar', $store->get('foo'));
    }

    public function testAddDoesNotUpdateHitMetadata(): void
    {
        CarbonImmutable::setTestNow('2000-01-01 00:00:00');

        $state = $this->createState();
        $store = $this->createStore($state, SwooleStore::EVICTION_POLICY_LRU);

        $this->setLogicalRow($state, $store, 'foo', 'bar', time() + 100, [
            'last_used_at' => 123.0,
            'used_count' => 7,
        ]);

        CarbonImmutable::setTestNow('2000-01-01 00:01:00');

        $this->assertFalse($store->add('foo', 'baz', 5));

        $row = $this->getLogicalRow($state, $store, 'foo');

        $this->assertSame(123.0, $row['last_used_at']);
        $this->assertSame(7, $row['used_count']);
    }

    public function testGetUnderTtlPolicyDoesNotUpdateHitMetadata(): void
    {
        $state = $this->createState();
        $store = $this->createStore($state, SwooleStore::EVICTION_POLICY_TTL);

        $this->setLogicalRow($state, $store, 'foo', 'bar', time() + 100, [
            'last_used_at' => 123.0,
            'used_count' => 7,
        ]);

        $this->assertSame('bar', $store->get('foo'));

        $row = $this->getLogicalRow($state, $store, 'foo');

        $this->assertSame(123.0, $row['last_used_at']);
        $this->assertSame(7, $row['used_count']);
    }

    public function testGetUnderNoEvictionPolicyDoesNotUpdateHitMetadata(): void
    {
        $state = $this->createState();
        $store = $this->createStore($state, SwooleStore::EVICTION_POLICY_NOEVICTION);

        $this->setLogicalRow($state, $store, 'foo', 'bar', time() + 100, [
            'last_used_at' => 123.0,
            'used_count' => 7,
        ]);

        $this->assertSame('bar', $store->get('foo'));

        $row = $this->getLogicalRow($state, $store, 'foo');

        $this->assertSame(123.0, $row['last_used_at']);
        $this->assertSame(7, $row['used_count']);
    }

    public function testGetUnderLruPolicyUpdatesOnlyLastUsedAt(): void
    {
        CarbonImmutable::setTestNow('2000-01-01 00:00:00');

        $state = $this->createState();
        $store = $this->createStore($state, SwooleStore::EVICTION_POLICY_LRU);
        $expiration = time() + 100;

        $this->setLogicalRow($state, $store, 'foo', 'bar', $expiration, [
            'last_used_at' => 123.0,
            'used_count' => 7,
        ]);

        CarbonImmutable::setTestNow('2000-01-01 00:01:00.123456');

        $this->assertSame('bar', $store->get('foo'));

        $row = $this->getLogicalRow($state, $store, 'foo');

        $this->assertSame(serialize('bar'), $row['value']);
        $this->assertSame((float) $expiration, $row['expiration']);
        $this->assertSame(7, $row['used_count']);
        $this->assertSame($this->getCurrentTimestamp(), $row['last_used_at']);
    }

    public function testGetUnderLfuPolicyUpdatesOnlyUsedCount(): void
    {
        CarbonImmutable::setTestNow('2000-01-01 00:00:00');

        $state = $this->createState();
        $store = $this->createStore($state, SwooleStore::EVICTION_POLICY_LFU);
        $expiration = time() + 100;

        $this->setLogicalRow($state, $store, 'foo', 'bar', $expiration, [
            'last_used_at' => 123.0,
            'used_count' => 7,
        ]);

        $this->assertSame('bar', $store->get('foo'));

        $row = $this->getLogicalRow($state, $store, 'foo');

        $this->assertSame(serialize('bar'), $row['value']);
        $this->assertSame((float) $expiration, $row['expiration']);
        $this->assertSame(123.0, $row['last_used_at']);
        $this->assertSame(8, $row['used_count']);
    }

    public function testLruHitMetadataCanCreateExpiredShellRowAfterConcurrentDelete(): void
    {
        $state = $this->createState();
        $store = new SwooleStoreEvictionProbe($state, 0.05, SwooleStore::EVICTION_POLICY_LRU, 0.05);
        $tableKey = $store->userTableKey('foo');

        $this->assertFalse($state->table()->get($tableKey));

        $store->recordHitForTableKey($tableKey);

        $row = $state->table()->get($tableKey);

        $this->assertNotFalse($row);
        $this->assertSame('', $row['value']);
        $this->assertSame(0.0, $row['expiration']);
        $this->assertNull($store->get('foo'));
        $this->assertFalse($state->table()->get($tableKey));
    }

    public function testLfuHitMetadataCanCreateExpiredShellRowAfterConcurrentDelete(): void
    {
        $state = $this->createState();
        $store = new SwooleStoreEvictionProbe($state, 0.05, SwooleStore::EVICTION_POLICY_LFU, 0.05);
        $tableKey = $store->userTableKey('foo');

        $this->assertFalse($state->table()->get($tableKey));

        $store->recordHitForTableKey($tableKey);

        $row = $state->table()->get($tableKey);

        $this->assertNotFalse($row);
        $this->assertSame('', $row['value']);
        $this->assertSame(0.0, $row['expiration']);
        $this->assertSame(1, $row['used_count']);
        $this->assertNull($store->get('foo'));
        $this->assertFalse($state->table()->get($tableKey));
    }

    public function testExpiredGetDeletesPhysicalRowAfterLockedRecheck(): void
    {
        $state = $this->createState();
        $store = $this->createStore($state);

        $this->setLogicalRow($state, $store, 'foo', 'bar', time() - 100);

        $this->assertNull($store->get('foo'));
        $this->assertFalse($this->getLogicalRow($state, $store, 'foo'));
    }

    public function testIncrementAndDecrementOperationsPreserveExpiration(): void
    {
        $state = $this->createState();
        $store = $this->createStore($state);
        $expiration = time() + 100;

        $this->setLogicalRow($state, $store, 'counter', 1, $expiration);

        $this->assertSame(3, $store->increment('counter', 2));
        $this->assertSame(1, $store->decrement('counter', 2));

        $row = $this->getLogicalRow($state, $store, 'counter');

        $this->assertSame(serialize(1), $row['value']);
        $this->assertSame((float) $expiration, $row['expiration']);
    }

    public function testIncrementCreatesPermanentMissingCounter(): void
    {
        CarbonImmutable::setTestNow('2000-01-01 00:00:00');

        $store = $this->createStore();

        $this->assertSame(1, $store->increment('counter'));

        CarbonImmutable::setTestNow('2002-01-01 00:00:00');

        $this->assertSame(1, $store->get('counter'));
    }

    public function testIncrementDoesNotWakeObjectPayloadUnderRowLock(): void
    {
        $state = $this->createState();
        $store = $this->createStore($state);
        SwooleStoreWakeupProbe::$wakeups = 0;

        $this->setLogicalRow($state, $store, 'counter', new SwooleStoreWakeupProbe, time() + 100);

        $this->expectException(TypeError::class);

        try {
            $store->increment('counter');
        } finally {
            $this->assertSame(0, SwooleStoreWakeupProbe::$wakeups);
        }
    }

    public function testForeverStoresValueIndefinitely(): void
    {
        CarbonImmutable::setTestNow('2000-01-01 00:00:00');

        $store = $this->createStore();

        $store->forever('foo', 'bar');

        CarbonImmutable::setTestNow('2002-01-01 00:00:00');

        $this->assertEquals('bar', $store->get('foo'));
    }

    public function testIntervalsCanBeRefreshed(): void
    {
        CarbonImmutable::setTestNow('2000-01-01 00:00:00');

        $store = $this->createStore();

        $store->interval('foo', fn () => Str::random(10), 1);

        $this->assertTrue(is_string($first = $store->get('foo')));

        CarbonImmutable::setTestNow('2002-01-01 00:00:00');

        $store->refreshIntervalCaches();

        $this->assertTrue(is_string($second = $store->get('foo')));
        $this->assertNotEquals($first, $second);
    }

    public function testCanForgetCacheItems(): void
    {
        $store = $this->createStore();

        $store->put('foo', 'bar', 5);
        $this->assertTrue($store->forget('foo'));

        $this->assertNull($store->get('foo'));
    }

    public function testFlushDeletesUserRowsAndPreservesControlRows(): void
    {
        $state = $this->createState();
        $store = $this->createStore($state);

        $store->put('foo', 'bar', 5);
        $store->interval('interval', fn () => 'value', 1);
        $this->assertTrue($store->lock('lock', 60)->acquire());
        $state->table()->set('legacy-row', [
            'value' => serialize('legacy'),
            'expiration' => time() + 100,
        ]);

        $this->assertTrue($store->flush());

        $this->assertNull($store->get('foo'));
        $this->assertFalse($state->table()->get('legacy-row'));
        $this->assertNotFalse($state->table()->get($this->tableKey($store, 'intervalKey', 'interval')));
        $this->assertNotFalse($state->table()->get($this->tableKey($store, 'lockKey', 'lock')));
        $this->assertSame('value', $store->get('interval'));
    }

    public function testFlushLocksDeletesLockRowsAndPreservesCacheAndIntervalRows(): void
    {
        $state = $this->createState();
        $store = $this->createStore($state);

        $store->put('foo', 'bar', 5);
        $store->interval('interval', fn () => 'value', 1);
        $this->assertTrue($store->lock('lock', 60, 'owner-1')->acquire());

        $this->assertTrue($store->flushLocks());

        $this->assertSame('bar', $store->get('foo'));
        $this->assertSame('value', $store->get('interval'));
        $this->assertTrue($store->lock('lock', 60, 'owner-2')->acquire());
    }

    public function testExpiredAtWithMicrosecond(): void
    {
        $store = $this->createStore();

        CarbonImmutable::setTestNow('2000-01-01 00:00:00.500000');
        $store->put('foo', 'bar', 1);

        CarbonImmutable::setTestNow('2000-01-01 00:00:01.499999');
        $this->assertSame('bar', $store->get('foo'));

        CarbonImmutable::setTestNow('2000-01-01 00:00:01.500000');
        $this->assertNull($store->get('foo'));
    }

    public function testPutDoesNotFlushStaleRecordsWhenMemoryLimitIsNotReached(): void
    {
        $state = $this->createState();
        $store = $this->createStore($state);

        $this->setLogicalRow($state, $store, 'expired', 'value', time() - 100);

        $store->put('fresh', 'value', 100);

        $this->assertNotFalse($this->getLogicalRow($state, $store, 'expired'));
    }

    public function testEvictRecordsFlushesStaleRecordsWhenCalledDirectly(): void
    {
        $state = $this->createState();
        $store = $this->createStore($state);

        $this->setLogicalRow($state, $store, 'expired', 'value', time() - 100);

        $store->evictRecords();

        $this->assertFalse($this->getLogicalRow($state, $store, 'expired'));
    }

    public function testEvictRecordsFlushesRecordsExpiringAtCurrentTimestamp(): void
    {
        CarbonImmutable::setTestNow('2000-01-01 00:00:00');

        $state = $this->createState();
        $store = $this->createStore($state);

        $this->setLogicalRow($state, $store, 'expired', 'value', $this->getCurrentTimestamp());

        $store->evictRecords();

        $this->assertFalse($this->getLogicalRow($state, $store, 'expired'));
    }

    public function testPutChecksEvictionOnlyWhenMemoryLimitIsReached(): void
    {
        $state = $this->createState();

        $store = new SwooleStoreEvictionSpy($state, false);
        $store->put('foo', 'bar', 60);
        $this->assertSame(0, $store->evictRecordsCalls);

        $store = new SwooleStoreEvictionSpy($state, true);
        $store->put('bar', 'baz', 60);
        $this->assertSame(1, $store->evictRecordsCalls);
    }

    public function testEvictRecordsNeverDeletesControlRows(): void
    {
        $state = $this->createState(rows: 8);
        $store = $this->createStore(
            state: $state,
            policy: SwooleStore::EVICTION_POLICY_TTL,
            memoryLimitBuffer: 1.0,
            evictionProportion: 1.0
        );

        $store->interval('interval', fn () => 'value', 1);
        $this->assertTrue($store->lock('lock', 60)->acquire());
        $store->put('foo', 'bar', 60);
        $state->table()->set('legacy-row', [
            'value' => serialize('legacy'),
            'expiration' => time() + 100,
        ]);

        $store->evictRecords();

        $this->assertFalse($state->table()->get('legacy-row'));
        $this->assertNotFalse($state->table()->get($this->tableKey($store, 'intervalKey', 'interval')));
        $this->assertNotFalse($state->table()->get($this->tableKey($store, 'lockKey', 'lock')));
    }

    public function testSingleLruEvictionPassHonorsEvictionProportion(): void
    {
        $state = $this->createState(rows: 8);
        $store = new SwooleStoreEvictionProbe(
            $state,
            0.05,
            SwooleStore::EVICTION_POLICY_LRU,
            2 / $state->table()->getSize()
        );

        $this->setLogicalRow($state, $store, 'oldest', 'value', time() + 100, ['last_used_at' => 10.0]);
        $this->setLogicalRow($state, $store, 'older', 'value', time() + 100, ['last_used_at' => 20.0]);
        $this->setLogicalRow($state, $store, 'newer', 'value', time() + 100, ['last_used_at' => 30.0]);
        $this->setLogicalRow($state, $store, 'newest', 'value', time() + 100, ['last_used_at' => 40.0]);

        $this->assertSame(2, $store->removeOnePolicyBatch());
        $this->assertFalse($this->getLogicalRow($state, $store, 'oldest'));
        $this->assertFalse($this->getLogicalRow($state, $store, 'older'));
        $this->assertNotFalse($this->getLogicalRow($state, $store, 'newer'));
        $this->assertNotFalse($this->getLogicalRow($state, $store, 'newest'));
    }

    public function testSingleLfuEvictionPassHonorsEvictionProportion(): void
    {
        $state = $this->createState(rows: 8);
        $store = new SwooleStoreEvictionProbe(
            $state,
            0.05,
            SwooleStore::EVICTION_POLICY_LFU,
            2 / $state->table()->getSize()
        );

        $this->setLogicalRow($state, $store, 'least', 'value', time() + 100, ['used_count' => 1]);
        $this->setLogicalRow($state, $store, 'less', 'value', time() + 100, ['used_count' => 2]);
        $this->setLogicalRow($state, $store, 'more', 'value', time() + 100, ['used_count' => 3]);
        $this->setLogicalRow($state, $store, 'most', 'value', time() + 100, ['used_count' => 4]);

        $this->assertSame(2, $store->removeOnePolicyBatch());
        $this->assertFalse($this->getLogicalRow($state, $store, 'least'));
        $this->assertFalse($this->getLogicalRow($state, $store, 'less'));
        $this->assertNotFalse($this->getLogicalRow($state, $store, 'more'));
        $this->assertNotFalse($this->getLogicalRow($state, $store, 'most'));
    }

    public function testSingleTtlEvictionPassHonorsEvictionProportion(): void
    {
        $state = $this->createState(rows: 8);
        $store = new SwooleStoreEvictionProbe(
            $state,
            0.05,
            SwooleStore::EVICTION_POLICY_TTL,
            2 / $state->table()->getSize()
        );
        $now = time();

        $this->setLogicalRow($state, $store, 'soonest', 'value', $now + 10);
        $this->setLogicalRow($state, $store, 'sooner', 'value', $now + 20);
        $this->setLogicalRow($state, $store, 'later', 'value', $now + 30);
        $this->setLogicalRow($state, $store, 'latest', 'value', $now + 40);

        $this->assertSame(2, $store->removeOnePolicyBatch());
        $this->assertFalse($this->getLogicalRow($state, $store, 'soonest'));
        $this->assertFalse($this->getLogicalRow($state, $store, 'sooner'));
        $this->assertNotFalse($this->getLogicalRow($state, $store, 'later'));
        $this->assertNotFalse($this->getLogicalRow($state, $store, 'latest'));
    }

    public function testEvictionCandidateDoesNotDeleteRowMutatedByPut(): void
    {
        $state = $this->createState();
        $store = new SwooleStoreEvictionProbe($state, 0.05, SwooleStore::EVICTION_POLICY_TTL, 0.05);
        $tableKey = $store->userTableKey('foo');

        $this->setLogicalRow($state, $store, 'foo', 'old', time() + 100);
        $fingerprint = $store->fingerprintFor($state->table()->get($tableKey));

        $this->assertTrue($store->put('foo', 'new', 60));

        $this->assertFalse($store->forgetCandidate($tableKey, $fingerprint));
        $this->assertSame('new', $store->get('foo'));
    }

    public function testEvictionCandidateDoesNotDeleteRowMutatedByIncrement(): void
    {
        $state = $this->createState();
        $store = new SwooleStoreEvictionProbe($state, 0.05, SwooleStore::EVICTION_POLICY_TTL, 0.05);
        $tableKey = $store->userTableKey('counter');

        $this->setLogicalRow($state, $store, 'counter', 1, time() + 100, [
            'last_used_at' => 123.0,
            'used_count' => 7,
        ]);
        $fingerprint = $store->fingerprintFor($state->table()->get($tableKey));

        $this->assertSame(2, $store->increment('counter'));

        $this->assertFalse($store->forgetCandidate($tableKey, $fingerprint));
        $this->assertSame(2, $store->get('counter'));
    }

    public function testEvictionCandidateDoesNotDeleteRowMutatedByLruHit(): void
    {
        CarbonImmutable::setTestNow('2000-01-01 00:00:00');

        $state = $this->createState();
        $store = new SwooleStoreEvictionProbe($state, 0.05, SwooleStore::EVICTION_POLICY_LRU, 0.05);
        $tableKey = $store->userTableKey('foo');

        $this->setLogicalRow($state, $store, 'foo', 'bar', time() + 100, [
            'last_used_at' => 123.0,
            'used_count' => 7,
        ]);
        $fingerprint = $store->fingerprintFor($state->table()->get($tableKey));

        CarbonImmutable::setTestNow('2000-01-01 00:01:00');

        $this->assertSame('bar', $store->get('foo'));

        $this->assertFalse($store->forgetCandidate($tableKey, $fingerprint));
        $this->assertSame('bar', $store->get('foo'));
    }

    public function testEvictionCandidateDoesNotDeleteRowMutatedByLfuHit(): void
    {
        $state = $this->createState();
        $store = new SwooleStoreEvictionProbe($state, 0.05, SwooleStore::EVICTION_POLICY_LFU, 0.05);
        $tableKey = $store->userTableKey('foo');

        $this->setLogicalRow($state, $store, 'foo', 'bar', time() + 100, [
            'last_used_at' => 123.0,
            'used_count' => 7,
        ]);
        $fingerprint = $store->fingerprintFor($state->table()->get($tableKey));

        $this->assertSame('bar', $store->get('foo'));

        $this->assertFalse($store->forgetCandidate($tableKey, $fingerprint));
        $this->assertSame('bar', $store->get('foo'));
    }

    public function testEvictRecordsPrunesExpiredLockRows(): void
    {
        CarbonImmutable::setTestNow('2000-01-01 00:00:00');

        $state = $this->createState();
        $store = $this->createStore($state);

        $this->assertTrue($store->lock('expired', 1)->acquire());

        CarbonImmutable::setTestNow('2000-01-01 00:00:02');

        $store->evictRecords();

        $this->assertFalse($state->table()->get($this->tableKey($store, 'lockKey', 'expired')));
    }

    public function testTouchPreservesValueAndChangesExpiration(): void
    {
        CarbonImmutable::setTestNow($now = CarbonImmutable::now());

        $state = $this->createState();
        $store = $this->createStore($state);

        $store->put('foo', 'bar', 30);
        $store->touch('foo', 60);

        CarbonImmutable::setTestNow($now->addSeconds(45));

        $this->assertSame('bar', $store->get('foo'));
    }

    public function testTouchReturnsFalseForNonExistentItem(): void
    {
        $store = $this->createStore();

        $this->assertFalse($store->touch('nonexistent', 60));
    }

    public function testTouchDeletesExpiredPhysicalRowAndReturnsFalse(): void
    {
        $state = $this->createState();
        $store = $this->createStore($state);

        $this->setLogicalRow($state, $store, 'foo', 'bar', time() - 100);

        $this->assertFalse($store->touch('foo', 60));
        $this->assertFalse($this->getLogicalRow($state, $store, 'foo'));
    }

    public function testDistinctLongLogicalKeysDoNotCollide(): void
    {
        $store = $this->createStore();
        $first = str_repeat('a', 63) . 'x';
        $second = str_repeat('a', 63) . 'y';

        $store->put($first, 'first', 60);
        $store->put($second, 'second', 60);

        $this->assertSame('first', $store->get($first));
        $this->assertSame('second', $store->get($second));
    }

    public function testInternalTableKeysStayUnderSwooleKeyLimit(): void
    {
        $store = $this->createStore();

        $this->assertLessThan(63, strlen($this->tableKey($store, 'userKey', str_repeat('u', 256))));
        $this->assertLessThan(63, strlen($this->tableKey($store, 'intervalKey', str_repeat('i', 256))));
        $this->assertLessThan(63, strlen($this->tableKey($store, 'lockKey', str_repeat('l', 256))));
    }

    public function testSeededHashesDifferByStateAndMatchWithinState(): void
    {
        $firstState = $this->createState(hashSeed: 1);
        $secondState = $this->createState(hashSeed: 2);

        $firstStore = $this->createStore($firstState);
        $sameStateStore = $this->createStore($firstState);
        $secondStore = $this->createStore($secondState);

        $this->assertSame(
            $this->tableKey($firstStore, 'userKey', 'foo'),
            $this->tableKey($sameStateStore, 'userKey', 'foo')
        );
        $this->assertNotSame(
            $this->tableKey($firstStore, 'userKey', 'foo'),
            $this->tableKey($secondStore, 'userKey', 'foo')
        );
    }

    public function testSwooleStoreImplementsLockContractsAndSupportsFlushingLocks(): void
    {
        $this->assertTrue(is_subclass_of(SwooleStore::class, LockProvider::class));
        $this->assertTrue(is_subclass_of(SwooleStore::class, CanFlushLocks::class));
        $this->assertTrue($this->createStore()->supportsFlushingLocks());
    }

    public function testSwooleStoreSupportsFunnels(): void
    {
        $repository = new Repository($this->createStore());
        $handled = false;

        $result = $repository->funnel('test-funnel')
            ->limit(1)
            ->releaseAfter(5)
            ->block(1)
            ->then(function () use (&$handled) {
                $handled = true;

                return 'ok';
            });

        $this->assertTrue($handled);
        $this->assertSame('ok', $result);
    }

    public function testLockAcquireSucceedsOnceWhileLive(): void
    {
        $store = $this->createStore();

        $this->assertTrue($store->lock('foo', 60, 'owner-1')->acquire());
        $this->assertFalse($store->lock('foo', 60, 'owner-2')->acquire());
    }

    public function testExpiredLocksCanBeAcquiredByNewOwner(): void
    {
        CarbonImmutable::setTestNow('2000-01-01 00:00:00');

        $store = $this->createStore();

        $this->assertTrue($store->lock('foo', 1, 'owner-1')->acquire());

        CarbonImmutable::setTestNow('2000-01-01 00:00:02');

        $this->assertTrue($store->lock('foo', 60, 'owner-2')->acquire());
    }

    public function testReleaseOnlyReleasesForOwner(): void
    {
        $store = $this->createStore();

        $this->assertTrue($store->lock('foo', 60, 'owner-1')->acquire());
        $this->assertFalse($store->lock('foo', 60, 'owner-2')->release());
        $this->assertFalse($store->lock('foo', 60, 'owner-2')->acquire());
        $this->assertTrue($store->lock('foo', 60, 'owner-1')->release());
        $this->assertTrue($store->lock('foo', 60, 'owner-2')->acquire());
    }

    public function testForceReleaseReleasesRegardlessOfOwner(): void
    {
        $store = $this->createStore();

        $this->assertTrue($store->lock('foo', 60, 'owner-1')->acquire());
        $store->lock('foo', 60, 'owner-2')->forceRelease();

        $this->assertTrue($store->lock('foo', 60, 'owner-2')->acquire());
    }

    public function testRestoreLockUsesSuppliedOwner(): void
    {
        $store = $this->createStore();

        $this->assertTrue($store->lock('foo', 60, 'owner-1')->acquire());
        $this->assertTrue($store->restoreLock('foo', 'owner-1')->release());
        $this->assertTrue($store->lock('foo', 60, 'owner-2')->acquire());
    }

    public function testRefreshExtendsLiveOwnedLock(): void
    {
        CarbonImmutable::setTestNow('2000-01-01 00:00:00');

        $store = $this->createStore();
        $lock = $store->lock('foo', 10, 'owner-1');

        $this->assertTrue($lock->acquire());

        CarbonImmutable::setTestNow('2000-01-01 00:00:05');

        $this->assertTrue($lock->refresh(20));
        $this->assertSame(20.0, $lock->getRemainingLifetime());
    }

    public function testPermanentLockSurvivesAndRefreshVerifiesOwnership(): void
    {
        CarbonImmutable::setTestNow('2000-01-01 00:00:00');

        $lock = $this->createStore()->lock('foo', 0);

        $this->assertTrue($lock->acquire());

        CarbonImmutable::setTestNow('2002-01-01 00:00:00');

        $this->assertTrue($lock->refresh());

        $lock->forceRelease();

        $this->assertFalse($lock->refresh());
    }

    public function testRefreshRejectsExplicitNonPositiveTtl(): void
    {
        $lock = $this->createStore()->lock('foo', 0);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Refresh requires a positive TTL. For a permanent lock, acquire it with seconds=0.');

        $lock->refresh(0);
    }

    public function testGetRemainingLifetimeReturnsNullForMissingExpiredAndPermanentLocks(): void
    {
        CarbonImmutable::setTestNow('2000-01-01 00:00:00');

        $store = $this->createStore();

        $this->assertNull($store->lock('missing', 60)->getRemainingLifetime());

        $permanent = $store->lock('permanent', 0);
        $this->assertTrue($permanent->acquire());
        $this->assertNull($permanent->getRemainingLifetime());

        $expiring = $store->lock('expiring', 1);
        $this->assertTrue($expiring->acquire());

        CarbonImmutable::setTestNow('2000-01-01 00:00:02');

        $this->assertNull($expiring->getRemainingLifetime());
    }

    private function createState(
        int $rows = 128,
        int $bytes = 10240,
        float $conflictProportion = 0.2,
        int $hashSeed = 12345
    ): SwooleTableState {
        return (new SwooleTableManager(m::mock(Container::class)))
            ->createState($rows, $bytes, $conflictProportion, $hashSeed);
    }

    private function createStore(
        ?SwooleTableState $state = null,
        string $policy = SwooleStore::EVICTION_POLICY_TTL,
        float $memoryLimitBuffer = 0.05,
        float $evictionProportion = 0.05
    ): SwooleStore {
        return new SwooleStore(
            $state ?? $this->createState(),
            $memoryLimitBuffer,
            $policy,
            $evictionProportion
        );
    }

    private function tableKey(SwooleStore $store, string $method, string $key): string
    {
        $reflection = new ReflectionMethod($store, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($store, $key);
    }

    private function setLogicalRow(
        SwooleTableState $state,
        SwooleStore $store,
        string $key,
        mixed $value,
        float $expiration,
        array $metadata = []
    ): void {
        $state->table()->set($this->tableKey($store, 'userKey', $key), array_merge([
            'value' => serialize($value),
            'expiration' => $expiration,
        ], $metadata));
    }

    private function getLogicalRow(SwooleTableState $state, SwooleStore $store, string $key): array|false
    {
        return $state->table()->get($this->tableKey($store, 'userKey', $key));
    }

    private function getCurrentTimestamp(): float
    {
        return CarbonImmutable::now()->getPreciseTimestamp(6) / 1000000;
    }
}

class SwooleStoreEvictionProbe extends SwooleStore
{
    public function removeOnePolicyBatch(): int
    {
        return $this->removeRecordsByEvictionPolicy();
    }

    public function userTableKey(string $key): string
    {
        return $this->userKey($key);
    }

    public function fingerprintFor(array $record): array
    {
        return $this->evictionFingerprint($record);
    }

    public function forgetCandidate(string $tableKey, array $fingerprint): bool
    {
        return $this->forgetEvictionCandidate($tableKey, $fingerprint);
    }

    public function recordHitForTableKey(string $tableKey): void
    {
        $this->recordHit($tableKey);
    }
}

class SwooleStorePutManyProbe extends SwooleStore
{
    /**
     * @var list<string>
     */
    public array $attempts = [];

    public function __construct(SwooleTableState $state, protected array $failures)
    {
        parent::__construct($state, 0.05, SwooleStore::EVICTION_POLICY_TTL, 0.05);
    }

    public function put(string $key, mixed $value, int $seconds): bool
    {
        $this->attempts[] = $key;

        return ! in_array($key, $this->failures, true);
    }
}

class SwooleStoreEvictionSpy extends SwooleStore
{
    public int $evictRecordsCalls = 0;

    public function __construct(SwooleTableState $state, protected bool $memoryLimitReached)
    {
        parent::__construct($state, 0.05, SwooleStore::EVICTION_POLICY_TTL, 0.05);
    }

    public function evictRecords(): void
    {
        ++$this->evictRecordsCalls;
    }

    protected function memoryLimitIsReached(): bool
    {
        return $this->memoryLimitReached;
    }
}

class SwooleStoreWakeupProbe
{
    public static int $wakeups = 0;

    public function __wakeup(): void
    {
        ++self::$wakeups;
    }
}
