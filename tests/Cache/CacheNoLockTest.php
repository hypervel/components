<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache;

use Hypervel\Cache\NoLock;
use Hypervel\Contracts\Cache\RefreshableLock;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;

class CacheNoLockTest extends TestCase
{
    public function testLockImplementsRefreshableLock(): void
    {
        $lock = new NoLock('foo', 10);

        $this->assertInstanceOf(RefreshableLock::class, $lock);
    }

    public function testAcquireAlwaysReturnsTrue(): void
    {
        $lock = new NoLock('foo', 10);

        $this->assertTrue($lock->acquire());
        $this->assertTrue($lock->acquire());
    }

    public function testReleaseAlwaysReturnsTrue(): void
    {
        $lock = new NoLock('foo', 10);

        $this->assertTrue($lock->release());
    }

    public function testRefreshReturnsTrue(): void
    {
        $lock = new NoLock('foo', 10);

        $this->assertTrue($lock->refresh());
        $this->assertTrue($lock->refresh(30));
    }

    public function testRefreshOnPermanentLockReturnsTrue(): void
    {
        $lock = new NoLock('foo', 0);

        $this->assertTrue($lock->refresh());
    }

    public function testRefreshWithExplicitZeroThrowsException(): void
    {
        $lock = new NoLock('foo', 10);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Refresh requires a positive TTL');

        $lock->refresh(0);
    }

    public function testRefreshWithNegativeSecondsThrowsException(): void
    {
        $lock = new NoLock('foo', 10);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Refresh requires a positive TTL');

        $lock->refresh(-5);
    }

    public function testGetRemainingLifetimeAlwaysReturnsNull(): void
    {
        $lock = new NoLock('foo', 10);

        $this->assertNull($lock->getRemainingLifetime());
    }

    public function testIsLockedAlwaysReturnsFalse(): void
    {
        $lock = new NoLock('foo', 10);

        $this->assertFalse($lock->isLocked());
    }

    public function testOwnerReturnsOwner(): void
    {
        $lock = new NoLock('foo', 10, 'custom-owner');

        $this->assertSame('custom-owner', $lock->owner());
    }

    public function testForceReleaseDoesNothing(): void
    {
        $lock = new NoLock('foo', 10);

        $lock->forceRelease();
        $this->assertTrue(true); // Just verify no exceptions
    }
}
