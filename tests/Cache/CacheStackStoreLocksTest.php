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
use JMac\Testing\Double;
use JMac\Testing\DoubleInterface;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;

class CacheStackStoreLocksTest extends TestCase
{
    use VerifiesDoubles;

    public function testLockDelegatesToBottomLayer(): void
    {
        $bottom = Double::for(Store::class, LockProvider::class);
        $lock = Double::for(Lock::class);

        $bottom->expects('lock')->with('name', 30, 'owner')->returns($lock);

        $stack = new StackStore([$this->plainStore(), $bottom]);

        $this->assertSame($lock, $stack->lock('name', 30, 'owner'));
    }

    public function testRestoreLockDelegatesToBottomLayer(): void
    {
        $bottom = Double::for(Store::class, LockProvider::class);
        $lock = Double::for(Lock::class);

        $bottom->expects('restoreLock')->with('name', 'owner')->returns($lock);

        $stack = new StackStore([$this->plainStore(), $bottom]);

        $this->assertSame($lock, $stack->restoreLock('name', 'owner'));
    }

    public function testLockThrowsWhenBottomLayerDoesNotSupportLocks(): void
    {
        $stack = new StackStore([$this->plainStore()]);

        $this->expectException(NotSupportedException::class);
        $this->expectExceptionMessageIsOrContains('does not support locks');

        $stack->lock('name');
    }

    public function testSupportsFlushingLocksReflectsBottomLayerProbe(): void
    {
        $bottom = Double::for(Store::class, CanFlushLocks::class);
        $bottom->expects('supportsFlushingLocks')->returns(true);

        $this->assertTrue((new StackStore([$this->plainStore(), $bottom]))->supportsFlushingLocks());
    }

    public function testSupportsFlushingLocksReturnsFalseWhenBottomLayerProbeIsFalse(): void
    {
        $bottom = Double::for(Store::class, CanFlushLocks::class);
        $bottom->expects('supportsFlushingLocks')->returns(false);

        $this->assertFalse((new StackStore([$this->plainStore(), $bottom]))->supportsFlushingLocks());
    }

    public function testSupportsFlushingLocksReturnsFalseWhenBottomLayerDoesNotImplementContract(): void
    {
        $this->assertFalse((new StackStore([$this->plainStore()]))->supportsFlushingLocks());
    }

    public function testFlushLocksDelegatesToBottomLayer(): void
    {
        $bottom = Double::for(Store::class, CanFlushLocks::class);
        $bottom->expects('supportsFlushingLocks')->returns(true);
        $bottom->expects('flushLocks')->returns(true);

        $this->assertTrue((new StackStore([$this->plainStore(), $bottom]))->flushLocks());
    }

    public function testFlushLocksThrowsWhenBottomLayerCannotFlushLocks(): void
    {
        $bottom = Double::for(Store::class, CanFlushLocks::class);
        $bottom->expects('supportsFlushingLocks')->returns(false);
        $bottom->expects('flushLocks')->never();

        $stack = new StackStore([$this->plainStore(), $bottom]);

        $this->expectException(NotSupportedException::class);
        $this->expectExceptionMessageIsOrContains('does not support flushing locks');

        $stack->flushLocks();
    }

    public function testHasSeparateLockStoreDelegatesToBottomLayer(): void
    {
        $bottom = Double::for(Store::class, CanFlushLocks::class);
        $bottom->expects('hasSeparateLockStore')->returns(true);

        $this->assertTrue((new StackStore([$this->plainStore(), $bottom]))->hasSeparateLockStore());
    }

    /**
     * Create a store without lock capabilities.
     */
    private function plainStore(): Store&DoubleInterface
    {
        return Double::for(Store::class);
    }
}
