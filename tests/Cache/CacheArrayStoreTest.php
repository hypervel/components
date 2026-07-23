<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache;

use Hypervel\Cache\ArrayStore;
use Hypervel\Contracts\Cache\RefreshableLock;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use stdClass;

class CacheArrayStoreTest extends TestCase
{
    public function testItemsCanBeSetAndRetrieved(): void
    {
        $store = new ArrayStore;
        $result = $store->put('foo', 'bar', 10);
        $this->assertTrue($result);
        $this->assertSame('bar', $store->get('foo'));
    }

    public function testCacheTtl(): void
    {
        $store = new ArrayStore;

        CarbonImmutable::setTestNow('2000-01-01 00:00:00.500'); // 500 milliseconds past
        $store->put('hello', 'world', 1);

        CarbonImmutable::setTestNow('2000-01-01 00:00:01.499'); // progress 0.999 seconds
        $this->assertSame('world', $store->get('hello'));

        CarbonImmutable::setTestNow('2000-01-01 00:00:01.500'); // progress 0.001 seconds. 1 second since putting into cache.
        $this->assertNull($store->get('hello'));
    }

    public function testMultipleItemsCanBeSetAndRetrieved(): void
    {
        $store = new ArrayStore;
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
    }

    public function testItemsCanExpire(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::now());

        $store = new ArrayStore;

        $store->put('foo', 'bar', 10);
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(10)->addSecond());
        $result = $store->get('foo');

        $this->assertNull($result);
    }

    public function testTouchExtendsTtl(): void
    {
        $store = new ArrayStore;

        CarbonImmutable::setTestNow($now = CarbonImmutable::now());

        $store->put('key', 'value', 30);
        $store->touch('key', 60);

        CarbonImmutable::setTestNow($now->addSeconds(45));

        $this->assertSame('value', $store->get('key'));
    }

    public function testStoreItemForeverProperlyStoresInArray(): void
    {
        $mock = $this->getMockBuilder(ArrayStore::class)->onlyMethods(['put'])->getMock();
        $mock->expects($this->once())
            ->method('put')->with($this->equalTo('foo'), $this->equalTo('bar'), $this->equalTo(0))
            ->willReturn(true);
        $result = $mock->forever('foo', 'bar');
        $this->assertTrue($result);
    }

    public function testValuesCanBeIncremented(): void
    {
        $store = new ArrayStore;
        $store->put('foo', 1, 10);
        $result = $store->increment('foo');
        $this->assertEquals(2, $result);
        $this->assertEquals(2, $store->get('foo'));

        $result = $store->increment('foo', 2);
        $this->assertEquals(4, $result);
        $this->assertEquals(4, $store->get('foo'));
    }

    public function testValuesGetCastedByIncrementOrDecrement(): void
    {
        $store = new ArrayStore;
        $store->put('foo', '1', 10);
        $result = $store->increment('foo');
        $this->assertEquals(2, $result);
        $this->assertEquals(2, $store->get('foo'));

        $store->put('bar', '1', 10);
        $result = $store->decrement('bar');
        $this->assertEquals(0, $result);
        $this->assertEquals(0, $store->get('bar'));
    }

    public function testIncrementNonNumericValues(): void
    {
        $store = new ArrayStore;
        $store->put('foo', 'I am string', 10);
        $result = $store->increment('foo');
        $this->assertEquals(1, $result);
        $this->assertEquals(1, $store->get('foo'));
    }

    public function testNonExistingKeysCanBeIncremented(): void
    {
        $store = new ArrayStore;
        $result = $store->increment('foo');
        $this->assertEquals(1, $result);
        $this->assertEquals(1, $store->get('foo'));

        // Will be there forever
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addYears(10));
        $this->assertEquals(1, $store->get('foo'));
    }

    public function testExpiredKeysAreIncrementedLikeNonExistingKeys(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::now());

        $store = new ArrayStore;

        $store->put('foo', 999, 10);
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(10)->addSecond());
        $result = $store->increment('foo');

        $this->assertEquals(1, $result);
    }

    public function testValuesCanBeDecremented(): void
    {
        $store = new ArrayStore;
        $store->put('foo', 1, 10);
        $result = $store->decrement('foo');
        $this->assertEquals(0, $result);
        $this->assertEquals(0, $store->get('foo'));

        $result = $store->decrement('foo', 2);
        $this->assertEquals(-2, $result);
        $this->assertEquals(-2, $store->get('foo'));
    }

    public function testItemsCanBeRemoved(): void
    {
        $store = new ArrayStore;
        $store->put('foo', 'bar', 10);
        $this->assertTrue($store->forget('foo'));
        $this->assertNull($store->get('foo'));
        $this->assertFalse($store->forget('foo'));
    }

    public function testItemsCanBeFlushed(): void
    {
        $store = new ArrayStore;
        $store->put('foo', 'bar', 10);
        $store->put('baz', 'boom', 10);
        $result = $store->flush();
        $this->assertTrue($result);
        $this->assertNull($store->get('foo'));
        $this->assertNull($store->get('baz'));
    }

    public function testLocksCanBeFlushed(): void
    {
        $store = new ArrayStore;
        $store->put('value', 'still-here', 10);

        $this->assertTrue($store->lock('foo', 10)->acquire());
        $this->assertTrue($store->lock('bar', 10)->acquire());
        $result = $store->flushLocks();
        $this->assertTrue($result);

        $this->assertTrue($store->lock('foo', 10)->acquire());
        $this->assertTrue($store->lock('bar', 10)->acquire());
        $this->assertSame('still-here', $store->get('value'));
    }

    public function testCacheKey(): void
    {
        $store = new ArrayStore;
        $this->assertEmpty($store->getPrefix());
    }

    public function testCannotAcquireLockTwice(): void
    {
        $store = new ArrayStore;
        $lock = $store->lock('foo', 10);

        $this->assertTrue($lock->acquire());
        $this->assertFalse($lock->acquire());
    }

    public function testCanAcquireLockAgainAfterExpiry(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::now());

        $store = new ArrayStore;
        $lock = $store->lock('foo', 10);
        $lock->acquire();
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(10));

        $this->assertTrue($lock->acquire());
    }

    public function testExpiredLockIsNotLockedOrOwned(): void
    {
        CarbonImmutable::setTestNow($now = CarbonImmutable::now());

        $store = new ArrayStore;
        $lock = $store->lock('foo', 10);
        $lock->acquire();

        $this->assertTrue($lock->isLocked());
        $this->assertTrue($lock->isOwnedByCurrentProcess());

        CarbonImmutable::setTestNow($now->addSeconds(10));

        $this->assertFalse($lock->isLocked());
        $this->assertFalse($lock->isOwnedByCurrentProcess());
        $this->assertNull($lock->getRemainingLifetime());
    }

    public function testLockExpirationLowerBoundary(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::now());

        $store = new ArrayStore;
        $lock = $store->lock('foo', 10);
        $lock->acquire();
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(10)->subMicrosecond());

        $this->assertFalse($lock->acquire());
    }

    public function testLockWithNoExpirationNeverExpires(): void
    {
        $store = new ArrayStore;
        $lock = $store->lock('foo');
        $lock->acquire();
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addYears(100));

        $this->assertFalse($lock->acquire());
    }

    public function testCanAcquireLockAfterRelease(): void
    {
        $store = new ArrayStore;
        $lock = $store->lock('foo', 10);
        $lock->acquire();

        $this->assertTrue($lock->release());
        $this->assertTrue($lock->acquire());
    }

    public function testAnotherOwnerCannotReleaseLock(): void
    {
        $store = new ArrayStore;
        $owner = $store->lock('foo', 10);
        $wannabeOwner = $store->lock('foo', 10);
        $owner->acquire();

        $this->assertFalse($wannabeOwner->release());
    }

    public function testExpiredLockCannotBeReleasedByOldOwner(): void
    {
        CarbonImmutable::setTestNow($now = CarbonImmutable::now());

        $store = new ArrayStore;
        $lock = $store->lock('foo', 10);
        $lock->acquire();

        CarbonImmutable::setTestNow($now->addSeconds(10));

        $this->assertFalse($lock->release());
    }

    public function testAnotherOwnerCanForceReleaseALock(): void
    {
        $store = new ArrayStore;
        $owner = $store->lock('foo', 10);
        $wannabeOwner = $store->lock('foo', 10);
        $owner->acquire();
        $wannabeOwner->forceRelease();

        $this->assertTrue($wannabeOwner->acquire());
    }

    public function testValuesAreNotStoredByReference(): void
    {
        $store = new ArrayStore($serialize = true);
        $object = new stdClass;
        $object->foo = true;

        $store->put('object', $object, 10);
        $object->bar = true;

        $retrievedObject = $store->get('object');

        $this->assertTrue($retrievedObject->foo);
        $this->assertFalse(property_exists($retrievedObject, 'bar'));
    }

    public function testValuesAreStoredByReferenceIfSerializationIsDisabled(): void
    {
        $store = new ArrayStore;
        $object = new stdClass;
        $object->foo = true;

        $store->put('object', $object, 10);
        $object->bar = true;

        $retrievedObject = $store->get('object');

        $this->assertTrue($retrievedObject->foo);
        $this->assertTrue($retrievedObject->bar);
    }

    public function testReleasingLockAfterAlreadyForceReleasedByAnotherOwnerFails(): void
    {
        $store = new ArrayStore;
        $owner = $store->lock('foo', 10);
        $wannabeOwner = $store->lock('foo', 10);
        $owner->acquire();
        $wannabeOwner->forceRelease();

        $this->assertFalse($wannabeOwner->release());
    }

    public function testOwnerStatusCanBeCheckedAfterRestoringLock(): void
    {
        $store = new ArrayStore;
        $firstLock = $store->lock('foo', 10);

        $this->assertTrue($firstLock->get());
        $owner = $firstLock->owner();

        $secondLock = $store->restoreLock('foo', $owner);
        $this->assertTrue($secondLock->isOwnedByCurrentProcess());
    }

    public function testOtherOwnerDoesNotOwnLockAfterRestore(): void
    {
        $store = new ArrayStore;
        $firstLock = $store->lock('foo', 10);

        $this->assertTrue($firstLock->get());

        $secondLock = $store->restoreLock('foo', 'other_owner');

        $this->assertFalse($secondLock->isOwnedByCurrentProcess());
    }

    public function testRestoringNonExistingLockDoesNotOwnAnything(): void
    {
        $store = new ArrayStore;
        $firstLock = $store->restoreLock('foo', 'owner');

        $this->assertFalse($firstLock->isOwnedByCurrentProcess());
    }

    public function testCanGetAll(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::now());

        $store = new ArrayStore(false);
        $store->put('foo', 'bar', 10);

        $this->assertEquals([
            'foo' => ['value' => 'bar', 'expiresAt' => CarbonImmutable::now()->addSeconds(10)->getPreciseTimestamp(3) / 1000],
        ], $store->all());
    }

    public function testSeparateArrayStoreInstancesDoNotShareContextData(): void
    {
        $first = new ArrayStore;
        $second = new ArrayStore;

        $first->put('key', 'first', 60);
        $second->put('key', 'second', 60);

        $this->assertSame('first', $first->get('key'));
        $this->assertSame('second', $second->get('key'));
    }

    public function testAllOnlyReturnsCurrentStoreContextData(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::now());

        $first = new ArrayStore;
        $second = new ArrayStore;

        $first->put('key', 'first', 60);
        $second->put('key', 'second', 60);

        $this->assertSame(['key' => 'first'], array_map(
            fn (array $item) => $item['value'],
            $first->all()
        ));
        $this->assertSame(['key' => 'second'], array_map(
            fn (array $item) => $item['value'],
            $second->all()
        ));
    }

    public function testCanGetAllWhenSerialized(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::now());

        $store = new ArrayStore(true);
        $store->put('foo', 'bar', 10);
        $this->assertEquals([
            'foo' => ['value' => 'bar', 'expiresAt' => $expiresAt = (CarbonImmutable::now()->addSeconds(10)->getPreciseTimestamp(3) / 1000)],
        ], $store->all());

        // Now let's put a serializable value in there
        $store->forget('foo');
        $store->put('foo', CarbonImmutable::now(), 10);

        $this->assertEquals([
            'foo' => [
                'value' => CarbonImmutable::now(),
                'expiresAt' => $expiresAt,
            ],
        ], $store->all());

        $this->assertEquals(
            serialize(CarbonImmutable::now()),
            $store->all(false)['foo']['value']
        );
    }

    public function testLockImplementsRefreshableLock(): void
    {
        $store = new ArrayStore;
        $lock = $store->lock('foo', 10);

        $this->assertInstanceOf(RefreshableLock::class, $lock);
    }

    public function testRefreshExtendsLockExpiration(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::now());

        $store = new ArrayStore;
        $lock = $store->lock('foo', 10);
        $lock->acquire();

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(5));

        $this->assertTrue($lock->refresh());

        // Lock should now expire 10 seconds from now, not 5
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(9));
        $this->assertFalse($store->lock('foo', 10)->acquire());

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(2));
        $this->assertTrue($store->lock('foo', 10)->acquire());
    }

    public function testRefreshWithCustomTtl(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::now());

        $store = new ArrayStore;
        $lock = $store->lock('foo', 10);
        $lock->acquire();

        $this->assertTrue($lock->refresh(30));

        // Lock should now expire 30 seconds from now
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(29));
        $this->assertFalse($store->lock('foo', 10)->acquire());

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(2));
        $this->assertTrue($store->lock('foo', 10)->acquire());
    }

    public function testRefreshReturnsFalseWhenLockDoesNotExist(): void
    {
        $store = new ArrayStore;
        $lock = $store->lock('foo', 10);

        $this->assertFalse($lock->refresh());
    }

    public function testRefreshReturnsFalseWhenNotOwned(): void
    {
        $store = new ArrayStore;
        $owner = $store->lock('foo', 10);
        $wannabeOwner = $store->lock('foo', 10);
        $owner->acquire();

        $this->assertFalse($wannabeOwner->refresh());
    }

    public function testRefreshReturnsFalseWhenExpired(): void
    {
        CarbonImmutable::setTestNow($now = CarbonImmutable::now());

        $store = new ArrayStore;
        $lock = $store->lock('foo', 10);
        $lock->acquire();

        CarbonImmutable::setTestNow($now->addSeconds(10));

        $this->assertFalse($lock->refresh());
    }

    public function testRefreshOnPermanentLockReturnsTrue(): void
    {
        $store = new ArrayStore;
        $lock = $store->lock('foo', 0);
        $lock->acquire();

        // No-op for permanent locks
        $this->assertTrue($lock->refresh());
    }

    public function testRefreshOnPermanentLockReturnsFalseWhenNotOwned(): void
    {
        $store = new ArrayStore;
        $owner = $store->lock('foo', 0);
        $wannabeOwner = $store->lock('foo', 0);
        $owner->acquire();

        $this->assertFalse($wannabeOwner->refresh());
    }

    public function testRefreshWithExplicitZeroThrowsException(): void
    {
        $store = new ArrayStore;
        $lock = $store->lock('foo', 10);
        $lock->acquire();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Refresh requires a positive TTL');

        $lock->refresh(0);
    }

    public function testRefreshWithNegativeSecondsThrowsException(): void
    {
        $store = new ArrayStore;
        $lock = $store->lock('foo', 10);
        $lock->acquire();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Refresh requires a positive TTL');

        $lock->refresh(-5);
    }

    public function testRefreshWithInvalidTtlThrowsEvenWhenNotOwned(): void
    {
        $store = new ArrayStore;
        $owner = $store->lock('foo', 10);
        $wannabeOwner = $store->lock('foo', 10);
        $owner->acquire();

        // Invalid parameters should throw regardless of ownership
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Refresh requires a positive TTL');

        $wannabeOwner->refresh(0);
    }

    public function testGetRemainingLifetimeReturnsSeconds(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::now());

        $store = new ArrayStore;
        $lock = $store->lock('foo', 10);
        $lock->acquire();

        $this->assertSame(10.0, $lock->getRemainingLifetime());

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(3));
        $this->assertSame(7.0, $lock->getRemainingLifetime());
    }

    public function testGetRemainingLifetimeReturnsNullWhenLockDoesNotExist(): void
    {
        $store = new ArrayStore;
        $lock = $store->lock('foo', 10);

        $this->assertNull($lock->getRemainingLifetime());
    }

    public function testGetRemainingLifetimeReturnsNullForInfiniteLock(): void
    {
        $store = new ArrayStore;
        $lock = $store->lock('foo');
        $lock->acquire();

        $this->assertNull($lock->getRemainingLifetime());
    }

    public function testGetRemainingLifetimeReturnsNullWhenExpired(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::now());

        $store = new ArrayStore;
        $lock = $store->lock('foo', 10);
        $lock->acquire();

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(15));
        $this->assertNull($lock->getRemainingLifetime());
    }
}
