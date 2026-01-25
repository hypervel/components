<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache;

use Hypervel\Contracts\Cache\RefreshableLock;
use Hypervel\Cache\NoLock;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;

/**
 * @internal
 * @coversNothing
 */
class CacheNoLockTest extends TestCase
{
    public function testLockImplementsRefreshableLock()
    {
        $lock = new NoLock('foo', 10);

        $this->assertInstanceOf(RefreshableLock::class, $lock);
    }

    public function testAcquireAlwaysReturnsTrue()
    {
        $lock = new NoLock('foo', 10);

        $this->assertTrue($lock->acquire());
        $this->assertTrue($lock->acquire());
    }

    public function testReleaseAlwaysReturnsTrue()
    {
        $lock = new NoLock('foo', 10);

        $this->assertTrue($lock->release());
    }

    public function testRefreshReturnsTrue()
    {
        $lock = new NoLock('foo', 10);

        $this->assertTrue($lock->refresh());
        $this->assertTrue($lock->refresh(30));
    }

    public function testRefreshOnPermanentLockReturnsTrue()
    {
        $lock = new NoLock('foo', 0);

        $this->assertTrue($lock->refresh());
    }

    public function testRefreshWithExplicitZeroThrowsException()
    {
        $lock = new NoLock('foo', 10);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Refresh requires a positive TTL');

        $lock->refresh(0);
    }

    public function testRefreshWithNegativeSecondsThrowsException()
    {
        $lock = new NoLock('foo', 10);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Refresh requires a positive TTL');

        $lock->refresh(-5);
    }

    public function testGetRemainingLifetimeAlwaysReturnsNull()
    {
        $lock = new NoLock('foo', 10);

        $this->assertNull($lock->getRemainingLifetime());
    }

    public function testOwnerReturnsOwner()
    {
        $lock = new NoLock('foo', 10, 'custom-owner');

        $this->assertSame('custom-owner', $lock->owner());
    }

    public function testForceReleaseDoesNothing()
    {
        $lock = new NoLock('foo', 10);

        $lock->forceRelease();
        $this->assertTrue(true); // Just verify no exceptions
    }
}
