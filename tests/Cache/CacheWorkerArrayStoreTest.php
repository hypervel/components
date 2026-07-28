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
