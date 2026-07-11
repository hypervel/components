<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Cache;

use Exception;
use Hypervel\Foundation\Testing\Concerns\InteractsWithRedis;
use Hypervel\Support\Facades\Cache;
use Hypervel\Testbench\TestCase;
use InvalidArgumentException;

class RedisCacheLockTest extends TestCase
{
    use InteractsWithRedis;

    public function testRedisLocksCanBeAcquiredAndReleased(): void
    {
        Cache::store('redis')->lock('foo')->forceRelease();

        $lock = Cache::store('redis')->lock('foo', 10);
        $this->assertTrue($lock->get());
        $this->assertFalse(Cache::store('redis')->lock('foo', 10)->get());
        $lock->release();

        $lock = Cache::store('redis')->lock('foo', 10);
        $this->assertTrue($lock->get());
        $this->assertFalse(Cache::store('redis')->lock('foo', 10)->get());
        Cache::store('redis')->lock('foo')->release();
    }

    public function testRedisLockCanHaveASeparateConnection(): void
    {
        $this->app['config']->set('cache.stores.redis.lock_connection', 'default');

        $this->assertSame('default', Cache::store('redis')->lock('foo')->getConnectionName());
    }

    public function testRedisLocksCanBlockForSeconds(): void
    {
        Cache::store('redis')->lock('foo')->forceRelease();
        $this->assertSame('taylor', Cache::store('redis')->lock('foo', 10)->block(1, function () {
            return 'taylor';
        }));

        Cache::store('redis')->lock('foo')->forceRelease();
        $this->assertTrue(Cache::store('redis')->lock('foo', 10)->block(1));
    }

    public function testConcurrentRedisLocksAreReleasedSafely(): void
    {
        Cache::store('redis')->lock('foo')->forceRelease();

        $firstLock = Cache::store('redis')->lock('foo', 1);
        $this->assertTrue($firstLock->get());
        sleep(2);

        $secondLock = Cache::store('redis')->lock('foo', 10);
        $this->assertTrue($secondLock->get());

        $firstLock->release();

        $this->assertFalse(Cache::store('redis')->lock('foo')->get());
    }

    public function testRedisLocksWithFailedBlockCallbackAreReleased(): void
    {
        Cache::store('redis')->lock('foo')->forceRelease();

        $firstLock = Cache::store('redis')->lock('foo', 10);

        try {
            $firstLock->block(1, function () {
                throw new Exception('failed');
            });
        } catch (Exception) {
            // Not testing the exception, just testing the lock
            // is released regardless of the how the exception
            // thrown by the callback was handled.
        }

        $secondLock = Cache::store('redis')->lock('foo', 1);

        $this->assertTrue($secondLock->get());
    }

    public function testRedisLocksCanBeReleasedUsingOwnerToken(): void
    {
        Cache::store('redis')->lock('foo')->forceRelease();

        $firstLock = Cache::store('redis')->lock('foo', 10);
        $this->assertTrue($firstLock->get());
        $owner = $firstLock->owner();

        $secondLock = Cache::store('redis')->restoreLock('foo', $owner);
        $secondLock->release();

        $this->assertTrue(Cache::store('redis')->lock('foo')->get());
    }

    public function testOwnerStatusCanBeCheckedAfterRestoringLock(): void
    {
        Cache::store('redis')->lock('foo')->forceRelease();

        $firstLock = Cache::store('redis')->lock('foo', 10);
        $this->assertTrue($firstLock->get());
        $owner = $firstLock->owner();

        $secondLock = Cache::store('redis')->restoreLock('foo', $owner);
        $this->assertTrue($secondLock->isOwnedByCurrentProcess());
    }

    public function testIsLocked(): void
    {
        Cache::store('redis')->lock('foo')->forceRelease();

        $lock = Cache::store('redis')->lock('foo', 10);
        $this->assertFalse($lock->isLocked());

        $lock->get();
        $this->assertTrue($lock->isLocked());

        $lock->release();
        $this->assertFalse($lock->isLocked());
    }

    public function testOtherOwnerDoesNotOwnLockAfterRestore(): void
    {
        Cache::store('redis')->lock('foo')->forceRelease();

        $firstLock = Cache::store('redis')->lock('foo', 10);
        $this->assertTrue($firstLock->isOwnedBy(null));
        $this->assertTrue($firstLock->get());
        $this->assertTrue($firstLock->isOwnedBy($firstLock->owner()));

        $secondLock = Cache::store('redis')->restoreLock('foo', 'other_owner');
        $this->assertTrue($secondLock->isOwnedBy($firstLock->owner()));
        $this->assertFalse($secondLock->isOwnedByCurrentProcess());
    }

    public function testRedisLockCanBeRefreshed(): void
    {
        Cache::store('redis')->lock('foo')->forceRelease();

        $lock = Cache::store('redis')->lock('foo', 10);
        $this->assertTrue($lock->get());

        $this->assertTrue($lock->refresh(20));
        $this->assertFalse(Cache::store('redis')->lock('foo', 10)->get());

        $lock->release();
    }

    public function testRedisLockCannotBeRefreshedByAnotherOwner(): void
    {
        Cache::store('redis')->lock('foo')->forceRelease();

        $firstLock = Cache::store('redis')->lock('foo', 10);
        $this->assertTrue($firstLock->get());

        $secondLock = Cache::store('redis')->restoreLock('foo', 'other_owner');

        $this->assertFalse($secondLock->refresh(20));
        $this->assertTrue($firstLock->refresh(20));

        $firstLock->release();
    }

    public function testRedisLockRefreshWithDefaultSeconds(): void
    {
        Cache::store('redis')->lock('foo')->forceRelease();

        $lock = Cache::store('redis')->lock('foo', 10);
        $this->assertTrue($lock->get());

        $this->assertTrue($lock->refresh());
        $this->assertFalse(Cache::store('redis')->lock('foo', 10)->get());

        $lock->release();
    }

    public function testRedisLockRefreshWithNoExpirationChecksOwnership(): void
    {
        Cache::store('redis')->lock('foo')->forceRelease();

        $lock = Cache::store('redis')->lock('foo');
        $this->assertTrue($lock->get());
        $this->assertTrue($lock->refresh());
        $this->assertFalse(Cache::store('redis')->lock('foo')->get());

        $lock->forceRelease();

        $this->assertFalse($lock->refresh());
    }

    public function testRedisLockRefreshWithZeroSecondsThrowsException(): void
    {
        Cache::store('redis')->lock('foo')->forceRelease();

        $lock = Cache::store('redis')->lock('foo', 10);
        $this->assertTrue($lock->get());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Refresh requires a positive TTL.');

        try {
            $lock->refresh(0);
        } finally {
            $lock->release();
        }
    }
}
