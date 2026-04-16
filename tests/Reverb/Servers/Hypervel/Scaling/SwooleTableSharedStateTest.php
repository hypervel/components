<?php

declare(strict_types=1);

namespace Hypervel\Tests\Reverb\Servers\Hypervel\Scaling;

use Hypervel\Reverb\Servers\Hypervel\Scaling\SwooleTableSharedState;
use Hypervel\Tests\Reverb\ReverbTestCase;
use RuntimeException;
use Swoole\Table;

class SwooleTableSharedStateTest extends ReverbTestCase
{
    protected SwooleTableSharedState $state;

    protected function setUp(): void
    {
        parent::setUp();

        $table = new Table(1024);
        $table->column('count', Table::TYPE_INT);
        $table->create();

        $lockTable = new Table(256);
        $lockTable->column('locked_at', Table::TYPE_FLOAT);
        $lockTable->create();

        $this->state = new SwooleTableSharedState($table, $lockTable);
    }

    public function testSubscribeReturnsChannelOccupiedOnFirstSubscriber()
    {
        $result = $this->state->subscribe('app1', 'test-channel');

        $this->assertTrue($result->channelOccupied);
        $this->assertFalse($result->channelVacated);
        $this->assertFalse($result->memberAdded);
        $this->assertFalse($result->memberRemoved);
    }

    public function testSubscribeReturnsChannelNotOccupiedOnSubsequentSubscriber()
    {
        $this->state->subscribe('app1', 'test-channel');
        $result = $this->state->subscribe('app1', 'test-channel');

        $this->assertFalse($result->channelOccupied);
    }

    public function testUnsubscribeReturnsChannelVacatedOnLastSubscriber()
    {
        $this->state->subscribe('app1', 'test-channel');
        $result = $this->state->unsubscribe('app1', 'test-channel');

        $this->assertTrue($result->channelVacated);
        $this->assertFalse($result->channelOccupied);
    }

    public function testUnsubscribeReturnsChannelNotVacatedWithRemainingSubscribers()
    {
        $this->state->subscribe('app1', 'test-channel');
        $this->state->subscribe('app1', 'test-channel');
        $result = $this->state->unsubscribe('app1', 'test-channel');

        $this->assertFalse($result->channelVacated);
    }

    public function testSubscribeReturnsMemberAddedOnFirstUserInstance()
    {
        $result = $this->state->subscribe('app1', 'presence-channel', 'user-1');

        $this->assertTrue($result->memberAdded);
        $this->assertFalse($result->memberRemoved);
    }

    public function testSubscribeReturnsMemberNotAddedOnDuplicateUser()
    {
        $this->state->subscribe('app1', 'presence-channel', 'user-1');
        $result = $this->state->subscribe('app1', 'presence-channel', 'user-1');

        $this->assertFalse($result->memberAdded);
    }

    public function testUnsubscribeReturnsMemberRemovedOnLastUserInstance()
    {
        $this->state->subscribe('app1', 'presence-channel', 'user-1');
        $result = $this->state->unsubscribe('app1', 'presence-channel', 'user-1');

        $this->assertTrue($result->memberRemoved);
    }

    public function testUnsubscribeReturnsMemberNotRemovedWithRemainingUserInstance()
    {
        $this->state->subscribe('app1', 'presence-channel', 'user-1');
        $this->state->subscribe('app1', 'presence-channel', 'user-1');
        $result = $this->state->unsubscribe('app1', 'presence-channel', 'user-1');

        $this->assertFalse($result->memberRemoved);
    }

    public function testSubscribeWithoutUserIdDoesNotTrackMembers()
    {
        $result = $this->state->subscribe('app1', 'test-channel');

        $this->assertFalse($result->memberAdded);
    }

    public function testDifferentAppsHaveIsolatedState()
    {
        $result1 = $this->state->subscribe('app1', 'test-channel');
        $result2 = $this->state->subscribe('app2', 'test-channel');

        $this->assertTrue($result1->channelOccupied);
        $this->assertTrue($result2->channelOccupied);
    }

    public function testAcquireConnectionSlotSucceedsWithinLimit()
    {
        $this->assertTrue($this->state->acquireConnectionSlot('app1', 5));
        $this->assertTrue($this->state->acquireConnectionSlot('app1', 5));
    }

    public function testAcquireConnectionSlotFailsAtLimit()
    {
        $this->state->acquireConnectionSlot('app1', 1);

        $this->assertFalse($this->state->acquireConnectionSlot('app1', 1));
    }

    public function testReleaseConnectionSlotFreesCapacity()
    {
        $this->state->acquireConnectionSlot('app1', 1);

        $this->assertFalse($this->state->acquireConnectionSlot('app1', 1));

        $this->state->releaseConnectionSlot('app1');

        $this->assertTrue($this->state->acquireConnectionSlot('app1', 1));
    }

    public function testReleaseConnectionSlotIsSafeWhenNoSlotAcquired()
    {
        $this->state->releaseConnectionSlot('app1');

        // No exception — just a no-op
        $this->assertTrue(true);
    }

    public function testThrowsExceptionWhenTableIsFull()
    {
        $smallTable = new Table(4);
        $smallTable->column('count', Table::TYPE_INT);
        $smallTable->create();

        $lockTable = new Table(4);
        $lockTable->column('locked_at', Table::TYPE_FLOAT);
        $lockTable->create();

        $state = new SwooleTableSharedState($smallTable, $lockTable);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Reverb shared state table is full');

        // Fill the table beyond capacity
        for ($i = 0; $i < 100; ++$i) {
            $state->subscribe('app1', "channel-{$i}");
        }
    }

    public function testFailedOpenDoesNotLeakConnectionSlot()
    {
        $this->state->acquireConnectionSlot('app1', 2);

        // Simulate a failed open — release the slot
        $this->state->releaseConnectionSlot('app1');

        // Both slots should be available
        $this->assertTrue($this->state->acquireConnectionSlot('app1', 2));
        $this->assertTrue($this->state->acquireConnectionSlot('app1', 2));
        $this->assertFalse($this->state->acquireConnectionSlot('app1', 2));
    }

    public function testUnsubscribeCleansUpZeroCountRows()
    {
        $this->state->subscribe('app1', 'test-channel', 'user-1');
        $this->state->unsubscribe('app1', 'test-channel', 'user-1');

        // Re-subscribe — should get channelOccupied again since row was cleaned
        $result = $this->state->subscribe('app1', 'test-channel', 'user-1');

        $this->assertTrue($result->channelOccupied);
        $this->assertTrue($result->memberAdded);
    }

    // ── Subscription count ────────────────────────────────────────────

    public function testSubscribeReturnsCorrectSubscriptionCount()
    {
        $result1 = $this->state->subscribe('app1', 'test-channel');
        $this->assertSame(1, $result1->subscriptionCount);

        $result2 = $this->state->subscribe('app1', 'test-channel');
        $this->assertSame(2, $result2->subscriptionCount);

        $result3 = $this->state->subscribe('app1', 'test-channel');
        $this->assertSame(3, $result3->subscriptionCount);
    }

    public function testUnsubscribeReturnsCorrectSubscriptionCount()
    {
        $this->state->subscribe('app1', 'test-channel');
        $this->state->subscribe('app1', 'test-channel');
        $this->state->subscribe('app1', 'test-channel');

        $result = $this->state->unsubscribe('app1', 'test-channel');
        $this->assertSame(2, $result->subscriptionCount);

        $result = $this->state->unsubscribe('app1', 'test-channel');
        $this->assertSame(1, $result->subscriptionCount);

        $result = $this->state->unsubscribe('app1', 'test-channel');
        $this->assertSame(0, $result->subscriptionCount);
    }

    public function testGetSubscriptionCountReturnsZeroForUnknownChannel()
    {
        $this->assertSame(0, $this->state->getSubscriptionCount('app1', 'nonexistent'));
    }

    public function testGetSubscriptionCountReturnsCurrentCount()
    {
        $this->state->subscribe('app1', 'test-channel');
        $this->state->subscribe('app1', 'test-channel');

        $this->assertSame(2, $this->state->getSubscriptionCount('app1', 'test-channel'));
    }

    public function testGetSubscriptionCountReturnsZeroAfterAllUnsubscribe()
    {
        $this->state->subscribe('app1', 'test-channel');
        $this->state->unsubscribe('app1', 'test-channel');

        $this->assertSame(0, $this->state->getSubscriptionCount('app1', 'test-channel'));
    }

    // ── User subscription count ───────────────────────────────────────

    public function testGetUserSubscriptionCountReturnsZeroForUnknownUser()
    {
        $this->assertSame(0, $this->state->getUserSubscriptionCount('app1', 'presence-channel', 'unknown'));
    }

    public function testGetUserSubscriptionCountReturnsCurrentCount()
    {
        $this->state->subscribe('app1', 'presence-channel', 'user-1');
        $this->state->subscribe('app1', 'presence-channel', 'user-1');

        $this->assertSame(2, $this->state->getUserSubscriptionCount('app1', 'presence-channel', 'user-1'));
    }

    public function testGetUserSubscriptionCountReturnsZeroAfterAllUnsubscribe()
    {
        $this->state->subscribe('app1', 'presence-channel', 'user-1');
        $this->state->unsubscribe('app1', 'presence-channel', 'user-1');

        $this->assertSame(0, $this->state->getUserSubscriptionCount('app1', 'presence-channel', 'user-1'));
    }

    // ── Subscription count lock ───────────────────────────────────────

    public function testTrySubscriptionCountLockAcquiresOnFirstCall()
    {
        $this->assertTrue($this->state->trySubscriptionCountLock('app1', 'test-channel', 5000));
    }

    public function testTrySubscriptionCountLockFailsWithinTtl()
    {
        $this->assertTrue($this->state->trySubscriptionCountLock('app1', 'test-channel', 5000));
        $this->assertFalse($this->state->trySubscriptionCountLock('app1', 'test-channel', 5000));
    }

    public function testTrySubscriptionCountLockSucceedsAfterTtlExpires()
    {
        $this->assertTrue($this->state->trySubscriptionCountLock('app1', 'test-channel', 50));

        usleep(60_000); // 60ms — well past the 50ms TTL

        $this->assertTrue($this->state->trySubscriptionCountLock('app1', 'test-channel', 50));
    }

    public function testClearSubscriptionCountLockAllowsReacquire()
    {
        $this->assertTrue($this->state->trySubscriptionCountLock('app1', 'test-channel', 5000));
        $this->assertFalse($this->state->trySubscriptionCountLock('app1', 'test-channel', 5000));

        $this->state->clearSubscriptionCountLock('app1', 'test-channel');

        $this->assertTrue($this->state->trySubscriptionCountLock('app1', 'test-channel', 5000));
    }

    // ── Cache miss lock ───────────────────────────────────────────────

    public function testTryCacheMissLockAcquiresOnFirstCall()
    {
        $this->assertTrue($this->state->tryCacheMissLock('app1', 'cache-channel', 10000));
    }

    public function testTryCacheMissLockFailsWithinTtl()
    {
        $this->assertTrue($this->state->tryCacheMissLock('app1', 'cache-channel', 10000));
        $this->assertFalse($this->state->tryCacheMissLock('app1', 'cache-channel', 10000));
    }

    public function testClearCacheMissLockAllowsReacquire()
    {
        $this->assertTrue($this->state->tryCacheMissLock('app1', 'cache-channel', 10000));
        $this->assertFalse($this->state->tryCacheMissLock('app1', 'cache-channel', 10000));

        $this->state->clearCacheMissLock('app1', 'cache-channel');

        $this->assertTrue($this->state->tryCacheMissLock('app1', 'cache-channel', 10000));
    }

    // ── Lock isolation ────────────────────────────────────────────────

    public function testLocksAreIsolatedBetweenApps()
    {
        $this->assertTrue($this->state->trySubscriptionCountLock('app1', 'test-channel', 5000));
        $this->assertTrue($this->state->trySubscriptionCountLock('app2', 'test-channel', 5000));
    }

    public function testLocksAreIsolatedBetweenChannels()
    {
        $this->assertTrue($this->state->trySubscriptionCountLock('app1', 'channel-a', 5000));
        $this->assertTrue($this->state->trySubscriptionCountLock('app1', 'channel-b', 5000));
    }

    // ── Smoothing markers ─────────────────────────────────────────────

    public function testClearSmoothingPendingReturnsTrueForLiveMarker()
    {
        $this->state->setSmoothingPending('app1', 'test-channel', 5000);

        $this->assertTrue($this->state->clearSmoothingPending('app1', 'test-channel', 5000));
    }

    public function testClearSmoothingPendingReturnsFalseWhenNoMarker()
    {
        $this->assertFalse($this->state->clearSmoothingPending('app1', 'test-channel', 5000));
    }

    public function testClearSmoothingPendingReturnsFalseForExpiredMarker()
    {
        $this->state->setSmoothingPending('app1', 'test-channel', 50);

        usleep(60_000); // 60ms — past the 50ms TTL

        $this->assertFalse($this->state->clearSmoothingPending('app1', 'test-channel', 50));
    }

    public function testClearSmoothingPendingConsumesMarkerOnlyOnce()
    {
        $this->state->setSmoothingPending('app1', 'test-channel', 5000);

        $this->assertTrue($this->state->clearSmoothingPending('app1', 'test-channel', 5000));
        $this->assertFalse($this->state->clearSmoothingPending('app1', 'test-channel', 5000));
    }

    public function testClearMemberSmoothingPendingReturnsTrueForLiveMarker()
    {
        $this->state->setMemberSmoothingPending('app1', 'presence-channel', 'user-1', 5000);

        $this->assertTrue($this->state->clearMemberSmoothingPending('app1', 'presence-channel', 'user-1', 5000));
    }

    public function testClearMemberSmoothingPendingReturnsFalseWhenNoMarker()
    {
        $this->assertFalse($this->state->clearMemberSmoothingPending('app1', 'presence-channel', 'user-1', 5000));
    }

    public function testClearMemberSmoothingPendingReturnsFalseForExpiredMarker()
    {
        $this->state->setMemberSmoothingPending('app1', 'presence-channel', 'user-1', 50);

        usleep(60_000);

        $this->assertFalse($this->state->clearMemberSmoothingPending('app1', 'presence-channel', 'user-1', 50));
    }

    // ── Lock table capacity ───────────────────────────────────────────

    public function testTryLockReturnsFalseWhenLockTableFull()
    {
        $table = new Table(1024);
        $table->column('count', Table::TYPE_INT);
        $table->create();

        $lockTable = new Table(4);
        $lockTable->column('locked_at', Table::TYPE_FLOAT);
        $lockTable->create();

        $state = new SwooleTableSharedState($table, $lockTable);

        // Fill the lock table past capacity — Swoole hash tables can hold
        // more than `size` rows via chaining, so use many iterations.
        $hitCapacity = false;

        for ($i = 0; $i < 10_000; ++$i) {
            if (! $state->tryCacheMissLock('app1', "channel-{$i}", 60000)) {
                $hitCapacity = true;
                break;
            }
        }

        $this->assertTrue($hitCapacity, 'Lock table should eventually return false when full');
    }
}
