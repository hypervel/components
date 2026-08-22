<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache;

use __PHP_Incomplete_Class;
use Hypervel\Cache\SerializableClassPolicy;
use Hypervel\Cache\WorkerArrayStore;
use Hypervel\Engine\Channel;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Tests\TestCase;
use stdClass;

use function Hypervel\Coroutine\parallel;

class CacheWorkerArrayStoreTest extends TestCase
{
    public function testItemsCanBeSetAndRetrieved(): void
    {
        $store = new WorkerArrayStore;

        $this->assertTrue($store->put('foo', 'bar', 10));
        $this->assertSame('bar', $store->get('foo'));
    }

    public function testItemsCanExpire(): void
    {
        CarbonImmutable::setTestNow('2000-01-01 00:00:00.500');

        $store = new WorkerArrayStore;
        $store->put('hello', 'world', 1);

        CarbonImmutable::setTestNow('2000-01-01 00:00:01.499');
        $this->assertSame('world', $store->get('hello'));

        CarbonImmutable::setTestNow('2000-01-01 00:00:01.500');
        $this->assertNull($store->get('hello'));
    }

    public function testSerializedValuesCanBeRetrievedRaw(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::now());

        $store = new WorkerArrayStore(true);
        $object = new stdClass;
        $object->name = 'Taylor';

        $store->put('object', $object, 10);

        $this->assertEquals($object, $store->get('object'));
        $this->assertSame(serialize($object), $store->all(false)['object']['value']);
    }

    public function testSerializableClassesControlSerializedValues(): void
    {
        $denyingStore = new WorkerArrayStore(true, false);
        $allowingStore = new WorkerArrayStore(
            serializesValues: true,
            serializableClasses: [stdClass::class],
        );

        $denyingStore->put('object', new stdClass, 10);
        $allowingStore->put('object', new stdClass, 10);

        $this->assertInstanceOf(__PHP_Incomplete_Class::class, $denyingStore->get('object'));
        $this->assertInstanceOf(stdClass::class, $allowingStore->get('object'));
    }

    public function testSerializableClassPolicyControlsSerializedValues(): void
    {
        $denyingStore = new WorkerArrayStore(
            serializesValues: true,
            serializableClassPolicy: new SerializableClassPolicy(static fn (): false => false),
        );
        $allowingStore = new WorkerArrayStore(
            serializesValues: true,
            serializableClassPolicy: new SerializableClassPolicy(static fn (): array => [stdClass::class]),
        );

        $denyingStore->put('object', new stdClass, 10);
        $allowingStore->put('object', new stdClass, 10);

        $this->assertInstanceOf(__PHP_Incomplete_Class::class, $denyingStore->get('object'));
        $this->assertInstanceOf(stdClass::class, $allowingStore->get('object'));
    }

    public function testValuesCanBeIncrementedAndDecremented(): void
    {
        $store = new WorkerArrayStore;

        $store->put('counter', 5, 10);

        $this->assertSame(7, $store->increment('counter', 2));
        $this->assertSame(6, $store->decrement('counter'));
        $this->assertSame(6, $store->get('counter'));
    }

    public function testTouchExtendsTtl(): void
    {
        $store = new WorkerArrayStore;

        CarbonImmutable::setTestNow($now = CarbonImmutable::now());

        $store->put('key', 'value', 30);
        $store->touch('key', 60);

        CarbonImmutable::setTestNow($now->addSeconds(45));

        $this->assertSame('value', $store->get('key'));
    }

    public function testTouchDoesNotReviveAnExpiredItem(): void
    {
        CarbonImmutable::setTestNow($now = CarbonImmutable::now());

        $store = new WorkerArrayStore;
        $store->put('key', 'value', 10);

        CarbonImmutable::setTestNow($now->addSeconds(10));

        $this->assertFalse($store->touch('key', 60));
        $this->assertArrayNotHasKey('key', $store->all(false));
    }

    public function testLocksCanBeRestoredRefreshedAndMeasured(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::now());

        $store = new WorkerArrayStore;
        $lock = $store->lock('foo', 10);

        $this->assertTrue($lock->get());

        $restoredLock = $store->restoreLock('foo', $lock->owner());
        $this->assertTrue($restoredLock->isOwnedByCurrentProcess());
        $this->assertSame(10.0, $restoredLock->getRemainingLifetime());

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(5));

        $this->assertTrue($restoredLock->refresh(30));
        $this->assertSame(30.0, $restoredLock->getRemainingLifetime());
    }

    public function testExactExpiredLockReadRemovesThePhysicalRecord(): void
    {
        CarbonImmutable::setTestNow($now = CarbonImmutable::now());

        $store = new InspectableWorkerArrayStore;
        $lock = $store->lock('expired', 10);
        $this->assertTrue($lock->acquire());

        CarbonImmutable::setTestNow($now->addSeconds(10));

        $this->assertNull($store->getLockRecord('expired'));
        $this->assertSame([], $store->lockRecords());
        $this->assertTrue($lock->acquire());
    }

    public function testUnrelatedWriteReclaimsExpiredRecordsAndPreservesLiveRecords(): void
    {
        CarbonImmutable::setTestNow($now = CarbonImmutable::now());
        $currentTimestamp = $now->getPreciseTimestamp(3) / 1000;
        $store = new InspectableWorkerArrayStore;
        $store->seedRecords(
            [
                'expired' => ['value' => 'expired', 'expiresAt' => $currentTimestamp - 1],
                'live' => ['value' => 'live', 'expiresAt' => $currentTimestamp + 60],
                'forever' => ['value' => 'forever', 'expiresAt' => 0.0],
            ],
            [
                'expired-lock' => ['owner' => 'expired', 'expiresAt' => $now->subSecond()],
                'live-lock' => ['owner' => 'live', 'expiresAt' => $now->addMinute()],
                'permanent-lock' => ['owner' => 'permanent', 'expiresAt' => null],
            ],
        );

        $store->forever('trigger', 'written');

        $this->assertSame(['live', 'forever', 'trigger'], array_keys($store->storedValues()));
        $this->assertSame(['live-lock', 'permanent-lock'], array_keys($store->lockRecords()));
    }

    public function testExistingRecordMutationsAdvanceReclamation(): void
    {
        CarbonImmutable::setTestNow($now = CarbonImmutable::now());
        $currentTimestamp = $now->getPreciseTimestamp(3) / 1000;
        $expiredValue = ['value' => 'expired', 'expiresAt' => $currentTimestamp - 1];

        $incrementStore = new InspectableWorkerArrayStore;
        $incrementStore->seedRecords([
            'expired' => $expiredValue,
            'counter' => ['value' => 1, 'expiresAt' => $currentTimestamp + 60],
        ], []);

        $this->assertSame(2, $incrementStore->increment('counter'));
        $this->assertSame(['counter'], array_keys($incrementStore->storedValues()));

        $touchStore = new InspectableWorkerArrayStore;
        $touchStore->seedRecords([
            'expired' => $expiredValue,
            'target' => ['value' => 'target', 'expiresAt' => $currentTimestamp + 60],
        ], []);

        $this->assertTrue($touchStore->touch('target', 120));
        $this->assertSame(['target'], array_keys($touchStore->storedValues()));

        $lockStore = new InspectableWorkerArrayStore;
        $lockStore->seedRecords(
            ['expired' => $expiredValue],
            [
                'target' => ['owner' => 'owner', 'expiresAt' => $now->addMinute()],
                'expired' => ['owner' => 'expired', 'expiresAt' => $now->subSecond()],
            ],
        );

        $this->assertTrue($lockStore->restoreLock('target', 'owner')->refresh(120));
        $this->assertSame([], $lockStore->storedValues());
        $this->assertSame(['target'], array_keys($lockStore->lockRecords()));
    }

    public function testOneWriteReclaimsOnlyTheFixedRecordBudgetFromEachMap(): void
    {
        CarbonImmutable::setTestNow($now = CarbonImmutable::now());
        $expiredValues = [];
        $expiredLocks = [];

        for ($index = 0; $index < 32; ++$index) {
            $expiredValues["value-{$index}"] = [
                'value' => $index,
                'expiresAt' => ($now->getPreciseTimestamp(3) / 1000) - 1,
            ];
            $expiredLocks["lock-{$index}"] = [
                'owner' => (string) $index,
                'expiresAt' => $now->subSecond(),
            ];
        }

        $store = new InspectableWorkerArrayStore;
        $store->seedRecords($expiredValues, $expiredLocks);

        $store->put('trigger', 'written', 60);

        $this->assertCount(25, $store->storedValues());
        $this->assertCount(24, $store->lockRecords());
    }

    public function testStorageCursorWrapsAfterCurrentDeletionAndAppendAtEnd(): void
    {
        CarbonImmutable::setTestNow($now = CarbonImmutable::now());
        $expiredValues = [];

        for ($index = 0; $index < 32; ++$index) {
            $expiredValues["expired-{$index}"] = [
                'value' => $index,
                'expiresAt' => ($now->getPreciseTimestamp(3) / 1000) - 1,
            ];
        }

        $store = new InspectableWorkerArrayStore;
        $store->seedRecords($expiredValues, []);
        $store->positionStorageAt(24);

        $copy = $store->all(false);
        unset($copy['expired-0']);
        $this->assertCount(32, $store->storedValues());

        $this->assertTrue($store->forget('expired-24'));

        for ($index = 0; $index < 5; ++$index) {
            $store->put("live-{$index}", $index, 60);
        }

        $this->assertSame(
            ['live-0', 'live-1', 'live-2', 'live-3', 'live-4'],
            array_keys($store->storedValues()),
        );
    }

    public function testExpiredLockReadAtTheCursorDoesNotBreakRotation(): void
    {
        CarbonImmutable::setTestNow($now = CarbonImmutable::now());
        $expiredLocks = [];

        for ($index = 0; $index < 16; ++$index) {
            $expiredLocks["expired-{$index}"] = [
                'owner' => (string) $index,
                'expiresAt' => $now->subSecond(),
            ];
        }

        $store = new InspectableWorkerArrayStore;
        $store->seedRecords([], $expiredLocks);
        $store->positionLocksAt(4);

        $this->assertNull($store->getLockRecord('expired-4'));

        for ($index = 0; $index < 3; ++$index) {
            $this->assertTrue($store->lock("live-{$index}", 60)->acquire());
        }

        $this->assertSame(
            ['live-0', 'live-1', 'live-2'],
            array_keys($store->lockRecords()),
        );
    }

    public function testFlushesResetMaintenanceAfterPointersHaveAdvanced(): void
    {
        CarbonImmutable::setTestNow($now = CarbonImmutable::now());
        $store = new InspectableWorkerArrayStore;
        $store->seedRecords(
            [
                'expired' => [
                    'value' => 'expired',
                    'expiresAt' => ($now->getPreciseTimestamp(3) / 1000) - 1,
                ],
            ],
            [
                'expired' => ['owner' => 'expired', 'expiresAt' => $now->subSecond()],
            ],
        );
        $store->positionStorageAt(0);
        $store->positionLocksAt(0);

        $this->assertTrue($store->flush());
        $this->assertTrue($store->flushLocks());
        $this->assertTrue($store->put('value', 'live', 60));
        $this->assertTrue($store->lock('lock', 60)->acquire());

        $this->assertSame(['value'], array_keys($store->storedValues()));
        $this->assertSame(['lock'], array_keys($store->lockRecords()));
    }

    public function testFlushClearsValuesButNotLocks(): void
    {
        $store = new WorkerArrayStore;

        $store->put('value', 'cached', 60);
        $this->assertTrue($store->lock('lock', 60)->acquire());

        $this->assertTrue($store->flush());

        $this->assertNull($store->get('value'));
        $this->assertFalse($store->lock('lock', 60)->acquire());
    }

    public function testFlushLocksClearsLocksButNotValues(): void
    {
        $store = new WorkerArrayStore;

        $store->put('value', 'cached', 60);
        $this->assertTrue($store->lock('lock', 60)->acquire());

        $this->assertTrue($store->flushLocks());

        $this->assertSame('cached', $store->get('value'));
        $this->assertTrue($store->lock('lock', 60)->acquire());
    }

    public function testTagsUseTheWorkerArrayStore(): void
    {
        $store = new WorkerArrayStore;

        $store->tags('tenant')->put('key', 'worker', 60);
        $store->tags('other')->put('key', 'other', 60);

        $this->assertSame('worker', $store->tags('tenant')->get('key'));
        $this->assertSame('other', $store->tags('other')->get('key'));

        $store->tags('tenant')->flush();

        $this->assertNull($store->tags('tenant')->get('key'));
        $this->assertSame('other', $store->tags('other')->get('key'));
    }

    public function testWorkerArrayValuesAreSharedAcrossCoroutines(): void
    {
        $store = new WorkerArrayStore;
        $written = new Channel(1);

        $results = parallel([
            'writer' => function () use ($store, $written) {
                $store->put('key', 'worker', 60);
                $written->push(true);

                return $store->get('key');
            },
            'reader' => function () use ($store, $written) {
                $written->pop();

                return $store->get('key');
            },
        ]);

        $this->assertSame('worker', $results['writer']);
        $this->assertSame('worker', $results['reader']);
    }

    public function testWorkerArrayLocksAreSharedAcrossCoroutines(): void
    {
        $store = new WorkerArrayStore;
        $acquired = new Channel(1);

        $results = parallel([
            'owner' => function () use ($store, $acquired) {
                $result = $store->lock('shared', 60)->acquire();
                $acquired->push(true);

                return $result;
            },
            'contender' => function () use ($store, $acquired) {
                $acquired->pop();

                return $store->lock('shared', 60)->acquire();
            },
        ]);

        $this->assertTrue($results['owner']);
        $this->assertFalse($results['contender']);
    }
}

class InspectableWorkerArrayStore extends WorkerArrayStore
{
    public function seedRecords(array $storage, array $locks): void
    {
        $this->storage = $storage;
        $this->locks = $locks;
    }

    public function storedValues(): array
    {
        return $this->storage;
    }

    public function lockRecords(): array
    {
        return $this->locks;
    }

    public function positionStorageAt(int $offset): void
    {
        reset($this->storage);

        for ($index = 0; $index < $offset; ++$index) {
            next($this->storage);
        }
    }

    public function positionLocksAt(int $offset): void
    {
        reset($this->locks);

        for ($index = 0; $index < $offset; ++$index) {
            next($this->locks);
        }
    }
}
