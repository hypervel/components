<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Cache;

use Exception;
use Hypervel\Contracts\Cache\LockTimeoutException;
use Hypervel\Contracts\Cache\RefreshableLock;
use Hypervel\Support\Facades\Cache;
use Hypervel\Support\Sleep;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Testbench\TestCase;
use InvalidArgumentException;
use Throwable;

#[WithConfig('cache.default', 'file')]
class FileCacheLockTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // flush lock from previous tests
        Cache::lock('foo')->forceRelease();
    }

    public function testLocksCanBeAcquiredAndReleased(): void
    {
        $lock = Cache::lock('foo', 10);
        $this->assertTrue($lock->get());
        $this->assertFalse(Cache::lock('foo', 10)->get());
        $lock->release();

        $lock = Cache::lock('foo', 10);
        $this->assertTrue($lock->get());
        $this->assertFalse(Cache::lock('foo', 10)->get());
        Cache::lock('foo')->release();
    }

    public function testLocksCanBlockForSeconds(): void
    {
        $this->assertSame('taylor', Cache::lock('foo', 10)->block(1, function () {
            return 'taylor';
        }));

        Cache::lock('foo')->forceRelease();
        $this->assertTrue(Cache::lock('foo', 10)->block(1));
    }

    public function testConcurrentLocksAreReleasedSafely(): void
    {
        Sleep::fake(syncWithCarbon: true);

        $firstLock = Cache::lock('foo', 1);
        $this->assertTrue($firstLock->get());
        Sleep::for(2)->seconds();

        $secondLock = Cache::lock('foo', 10);
        $this->assertTrue($secondLock->get());

        $firstLock->release();

        $this->assertFalse(Cache::lock('foo')->get());
    }

    public function testLocksWithFailedBlockCallbackAreReleased(): void
    {
        $firstLock = Cache::lock('foo', 10);

        try {
            $firstLock->block(1, function () {
                throw new Exception('failed');
            });
        } catch (Exception) {
            // Not testing the exception, just testing the lock
            // is released regardless of the how the exception
            // thrown by the callback was handled.
        }

        $secondLock = Cache::lock('foo', 1);

        $this->assertTrue($secondLock->get());
    }

    public function testLocksCanBeReleasedUsingOwnerToken(): void
    {
        $firstLock = Cache::lock('foo', 10);
        $this->assertTrue($firstLock->get());
        $owner = $firstLock->owner();

        $secondLock = Cache::store('file')->restoreLock('foo', $owner);
        $secondLock->release();

        $this->assertTrue(Cache::lock('foo')->get());
    }

    public function testOwnerStatusCanBeCheckedAfterRestoringLock(): void
    {
        $firstLock = Cache::lock('foo', 10);
        $this->assertTrue($firstLock->get());
        $owner = $firstLock->owner();

        $secondLock = Cache::store('file')->restoreLock('foo', $owner);
        $this->assertTrue($secondLock->isOwnedByCurrentProcess());
    }

    public function testCacheRememberReturnsValueWhenLockWithSameKeyExists(): void
    {
        $lock = Cache::lock('my-key', 5);
        $this->assertTrue($lock->get());

        $value = Cache::remember('my-key', 60, fn () => 'expected-value');

        $this->assertSame('expected-value', $value);

        $lock->release();
    }

    public function testIsLocked(): void
    {
        $lock = Cache::lock('foo', 10);
        $this->assertFalse($lock->isLocked());

        $lock->get();
        $this->assertTrue($lock->isLocked());

        $lock->release();
        $this->assertFalse($lock->isLocked());
    }

    public function testOtherOwnerDoesNotOwnLockAfterRestore(): void
    {
        $firstLock = Cache::lock('foo', 10);
        $this->assertTrue($firstLock->isOwnedBy(null));
        $this->assertTrue($firstLock->get());
        $this->assertTrue($firstLock->isOwnedBy($firstLock->owner()));

        $secondLock = Cache::store('file')->restoreLock('foo', 'other_owner');
        $this->assertTrue($secondLock->isOwnedBy($firstLock->owner()));
        $this->assertFalse($secondLock->isOwnedByCurrentProcess());
    }

    public function testExceptionIfBlockCanNotAcquireLock(): void
    {
        Sleep::fake(syncWithCarbon: true);

        // acquire and not release lock
        Cache::lock('foo', 10)->get();

        // try to get lock and hit block timeout
        $this->expectException(LockTimeoutException::class);
        Cache::lock('foo', 10)->block(5);
    }

    public function testLockImplementsRefreshableLock(): void
    {
        $this->assertInstanceOf(RefreshableLock::class, Cache::lock('foo', 10));
    }

    public function testLockCanBeRefreshed(): void
    {
        $lock = Cache::lock('foo', 10);
        $this->assertTrue($lock->get());

        $this->assertTrue($lock->refresh(20));
        $this->assertFalse(Cache::lock('foo', 10)->get());

        $lock->release();
    }

    public function testLockCannotBeRefreshedByAnotherOwner(): void
    {
        $firstLock = Cache::lock('foo', 10);
        $this->assertTrue($firstLock->get());

        $secondLock = Cache::store('file')->restoreLock('foo', 'other_owner');

        $this->assertFalse($secondLock->refresh(20));
        $this->assertTrue($firstLock->refresh(20));

        $firstLock->release();
    }

    public function testLockRefreshWithDefaultSeconds(): void
    {
        $this->freezeTime();

        $lock = Cache::lock('foo', 10);
        $this->assertTrue($lock->get());

        $this->travel(5)->seconds();

        $this->assertTrue($lock->refresh());
        $this->assertSame(10.0, $lock->getRemainingLifetime());

        $lock->release();
    }

    public function testRefreshReturnsFalseAfterExpiry(): void
    {
        $this->freezeTime();

        $lock = Cache::lock('foo', 10);
        $this->assertTrue($lock->get());

        $this->travel(10)->seconds();

        $this->assertFalse($lock->refresh());
    }

    public function testRefreshOnPermanentLockVerifiesOwnership(): void
    {
        $lock = Cache::lock('foo');

        $this->assertTrue($lock->get());
        $this->assertTrue($lock->refresh());
        $this->assertFalse(Cache::store('file')->restoreLock('foo', 'other_owner')->refresh());
    }

    public function testRefreshWithExplicitZeroThrowsException(): void
    {
        $lock = Cache::lock('foo', 10);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Refresh requires a positive TTL');

        $lock->refresh(0);
    }

    public function testGetRemainingLifetimeReturnsSeconds(): void
    {
        $this->freezeTime();

        $lock = Cache::lock('foo', 10);

        $this->assertTrue($lock->get());
        $this->assertSame(10.0, $lock->getRemainingLifetime());

        $this->travel(3)->seconds();

        $this->assertSame(7.0, $lock->getRemainingLifetime());
    }

    public function testGetRemainingLifetimeReturnsNullWhenLockDoesNotExist(): void
    {
        $this->assertNull(Cache::lock('foo', 10)->getRemainingLifetime());
    }

    public function testGetRemainingLifetimeReturnsNullForPermanentLock(): void
    {
        $lock = Cache::lock('foo');

        $this->assertTrue($lock->get());
        $this->assertNull($lock->getRemainingLifetime());
    }

    protected function tearDown(): void
    {
        try {
            Cache::lock('foo')->forceRelease();
        } catch (Throwable) {
        }

        parent::tearDown();
    }
}
