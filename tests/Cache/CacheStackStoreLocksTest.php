<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache;

use Hypervel\Cache\Exceptions\NotSupportedException;
use Hypervel\Cache\StackStore;
use Hypervel\Contracts\Cache\CanFlushLocks;
use Hypervel\Contracts\Cache\Lock;
use Hypervel\Contracts\Cache\LockProvider;
use Hypervel\Contracts\Cache\Store;
use Hypervel\Tests\TestCase;
use Mockery as m;

class CacheStackStoreLocksTest extends TestCase
{
    public function testLockDelegatesToBottomLayer(): void
    {
        $bottom = m::mock(Store::class, LockProvider::class);
        $lock = m::mock(Lock::class);

        $bottom->shouldReceive('lock')->once()->with('name', 30, 'owner')->andReturn($lock);

        $stack = new StackStore([$this->plainStore(), $bottom]);

        $this->assertSame($lock, $stack->lock('name', 30, 'owner'));
    }

    public function testRestoreLockDelegatesToBottomLayer(): void
    {
        $bottom = m::mock(Store::class, LockProvider::class);
        $lock = m::mock(Lock::class);

        $bottom->shouldReceive('restoreLock')->once()->with('name', 'owner')->andReturn($lock);

        $stack = new StackStore([$this->plainStore(), $bottom]);

        $this->assertSame($lock, $stack->restoreLock('name', 'owner'));
    }

    public function testLockThrowsWhenBottomLayerDoesNotSupportLocks(): void
    {
        $stack = new StackStore([$this->plainStore()]);

        $this->expectException(NotSupportedException::class);
        $this->expectExceptionMessage('does not support locks');

        $stack->lock('name');
    }

    public function testSupportsFlushingLocksReflectsBottomLayerProbe(): void
    {
        $bottom = m::mock(Store::class, CanFlushLocks::class);
        $bottom->shouldReceive('supportsFlushingLocks')->once()->andReturnTrue();

        $this->assertTrue((new StackStore([$this->plainStore(), $bottom]))->supportsFlushingLocks());
    }

    public function testSupportsFlushingLocksReturnsFalseWhenBottomLayerProbeIsFalse(): void
    {
        $bottom = m::mock(Store::class, CanFlushLocks::class);
        $bottom->shouldReceive('supportsFlushingLocks')->once()->andReturnFalse();

        $this->assertFalse((new StackStore([$this->plainStore(), $bottom]))->supportsFlushingLocks());
    }

    public function testSupportsFlushingLocksReturnsFalseWhenBottomLayerDoesNotImplementContract(): void
    {
        $this->assertFalse((new StackStore([$this->plainStore()]))->supportsFlushingLocks());
    }

    public function testFlushLocksDelegatesToBottomLayer(): void
    {
        $bottom = m::mock(Store::class, CanFlushLocks::class);
        $bottom->shouldReceive('supportsFlushingLocks')->once()->andReturnTrue();
        $bottom->shouldReceive('flushLocks')->once()->andReturnTrue();

        $this->assertTrue((new StackStore([$this->plainStore(), $bottom]))->flushLocks());
    }

    public function testFlushLocksThrowsWhenBottomLayerCannotFlushLocks(): void
    {
        $bottom = m::mock(Store::class, CanFlushLocks::class);
        $bottom->shouldReceive('supportsFlushingLocks')->once()->andReturnFalse();
        $bottom->shouldNotReceive('flushLocks');

        $stack = new StackStore([$this->plainStore(), $bottom]);

        $this->expectException(NotSupportedException::class);
        $this->expectExceptionMessage('does not support flushing locks');

        $stack->flushLocks();
    }

    public function testHasSeparateLockStoreDelegatesToBottomLayer(): void
    {
        $bottom = m::mock(Store::class, CanFlushLocks::class);
        $bottom->shouldReceive('hasSeparateLockStore')->once()->andReturnTrue();

        $this->assertTrue((new StackStore([$this->plainStore(), $bottom]))->hasSeparateLockStore());
    }

    private function plainStore(): Store|m\MockInterface
    {
        return m::mock(Store::class);
    }
}
