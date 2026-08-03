<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Reverb;

use Hypervel\Foundation\Testing\Concerns\InteractsWithRedis;
use Hypervel\Reverb\Servers\Hypervel\Scaling\RedisSharedState;
use Hypervel\Support\Facades\Redis;
use Hypervel\Testbench\TestCase;

use function Hypervel\Coroutine\go;

/**
 * Integration tests for RedisSharedState against a real Redis server.
 */
class RedisSharedStateTest extends TestCase
{
    use InteractsWithRedis;

    protected RedisSharedState $state;

    protected function setUp(): void
    {
        parent::setUp();

        $this->state = new RedisSharedState(Redis::connection());
    }

    // ── Channel subscription tracking ──────────────────────────────────

    public function testSubscribeReturnsChannelOccupiedOnFirstSubscriber(): void
    {
        $result = $this->state->subscribe('app1', 'test-channel');

        $this->assertTrue($result->channelOccupied);
        $this->assertFalse($result->channelVacated);
        $this->assertFalse($result->memberAdded);
        $this->assertFalse($result->memberRemoved);
    }

    public function testSubscribeReturnsChannelNotOccupiedOnSubsequentSubscriber(): void
    {
        $this->state->subscribe('app1', 'test-channel');
        $result = $this->state->subscribe('app1', 'test-channel');

        $this->assertFalse($result->channelOccupied);
    }

    public function testUnsubscribeReturnsChannelVacatedOnLastSubscriber(): void
    {
        $this->state->subscribe('app1', 'test-channel');
        $result = $this->state->unsubscribe('app1', 'test-channel');

        $this->assertTrue($result->channelVacated);
        $this->assertFalse($result->channelOccupied);
    }

    public function testUnsubscribeReturnsChannelNotVacatedWithRemainingSubscribers(): void
    {
        $this->state->subscribe('app1', 'test-channel');
        $this->state->subscribe('app1', 'test-channel');
        $result = $this->state->unsubscribe('app1', 'test-channel');

        $this->assertFalse($result->channelVacated);
    }

    // ── Presence user tracking ─────────────────────────────────────────

    public function testSubscribeReturnsMemberAddedOnFirstUserInstance(): void
    {
        $result = $this->state->subscribe('app1', 'presence-channel', 'user-1');

        $this->assertTrue($result->memberAdded);
        $this->assertFalse($result->memberRemoved);
    }

    public function testSubscribeReturnsMemberNotAddedOnDuplicateUser(): void
    {
        $this->state->subscribe('app1', 'presence-channel', 'user-1');
        $result = $this->state->subscribe('app1', 'presence-channel', 'user-1');

        $this->assertFalse($result->memberAdded);
    }

    public function testUnsubscribeReturnsMemberRemovedOnLastUserInstance(): void
    {
        $this->state->subscribe('app1', 'presence-channel', 'user-1');
        $result = $this->state->unsubscribe('app1', 'presence-channel', 'user-1');

        $this->assertTrue($result->memberRemoved);
    }

    public function testUnsubscribeReturnsMemberNotRemovedWithRemainingUserInstance(): void
    {
        $this->state->subscribe('app1', 'presence-channel', 'user-1');
        $this->state->subscribe('app1', 'presence-channel', 'user-1');
        $result = $this->state->unsubscribe('app1', 'presence-channel', 'user-1');

        $this->assertFalse($result->memberRemoved);
    }

    public function testSubscribeWithoutUserIdDoesNotTrackMembers(): void
    {
        $result = $this->state->subscribe('app1', 'test-channel');

        $this->assertFalse($result->memberAdded);
    }

    // ── Connection slots ───────────────────────────────────────────────

    public function testAcquireConnectionSlotSucceedsWithinLimit(): void
    {
        $this->assertTrue($this->state->acquireConnectionSlot('app1', 5));
        $this->assertTrue($this->state->acquireConnectionSlot('app1', 5));
    }

    public function testAcquireConnectionSlotFailsAtLimit(): void
    {
        $this->state->acquireConnectionSlot('app1', 1);

        $this->assertFalse($this->state->acquireConnectionSlot('app1', 1));
    }

    public function testReleaseConnectionSlotFreesCapacity(): void
    {
        $this->state->acquireConnectionSlot('app1', 1);

        $this->assertFalse($this->state->acquireConnectionSlot('app1', 1));

        $this->state->releaseConnectionSlot('app1');

        $this->assertTrue($this->state->acquireConnectionSlot('app1', 1));
    }

    public function testReleaseConnectionSlotIsSafeWhenNoSlotAcquired(): void
    {
        $this->state->releaseConnectionSlot('app1');

        // No exception — just a no-op
        $this->assertTrue(true);
    }

    // ── Key cleanup ────────────────────────────────────────────────────

    public function testUnsubscribeCleansUpZeroCountKeys(): void
    {
        $this->state->subscribe('app1', 'test-channel', 'user-1');
        $this->state->unsubscribe('app1', 'test-channel', 'user-1');

        // Re-subscribe — should get channelOccupied again since key was cleaned
        $result = $this->state->subscribe('app1', 'test-channel', 'user-1');

        $this->assertTrue($result->channelOccupied);
        $this->assertTrue($result->memberAdded);
    }

    // ── App isolation ──────────────────────────────────────────────────

    public function testDifferentAppsHaveIsolatedState(): void
    {
        $result1 = $this->state->subscribe('app1', 'test-channel');
        $result2 = $this->state->subscribe('app2', 'test-channel');

        $this->assertTrue($result1->channelOccupied);
        $this->assertTrue($result2->channelOccupied);
    }

    public function testDelimiterBearingLogicalKeysRemainDistinct(): void
    {
        $first = $this->state->subscribe('app:one', 'channel');
        $second = $this->state->subscribe('app', 'one:channel');

        $this->assertTrue($first->channelOccupied);
        $this->assertTrue($second->channelOccupied);
        $this->assertSame(1, $this->state->getSubscriptionCount('app:one', 'channel'));
        $this->assertSame(1, $this->state->getSubscriptionCount('app', 'one:channel'));
        $this->assertTrue($this->state->trySubscriptionCountLock('app:one', 'channel'));
        $this->assertTrue($this->state->trySubscriptionCountLock('app', 'one:channel'));
    }

    public function testChannelAndMemberSmoothingMarkersRemainDistinct(): void
    {
        $this->state->setSmoothingPending('app', 'channel:user', 5000);
        $this->state->setMemberSmoothingPending('app', 'channel', 'user', 5000);

        $this->assertTrue($this->state->clearSmoothingPending('app', 'channel:user', 5000));
        $this->assertTrue($this->state->clearMemberSmoothingPending('app', 'channel', 'user', 5000));
    }

    // ── Concurrency ────────────────────────────────────────────────────

    public function testConcurrentSubscribeUnsubscribeProducesCorrectCounts(): void
    {
        // Run 50 subscribes and 50 unsubscribes in parallel coroutines
        $channel = new \Swoole\Coroutine\Channel(100);

        for ($i = 0; $i < 50; ++$i) {
            go(function () use ($channel) {
                $this->state->subscribe('app1', 'concurrent-channel');
                $channel->push(true);
            });
        }

        // Wait for all subscribes to complete
        for ($i = 0; $i < 50; ++$i) {
            $channel->pop(5);
        }

        // Now unsubscribe 49 — one should remain
        for ($i = 0; $i < 49; ++$i) {
            go(function () use ($channel) {
                $this->state->unsubscribe('app1', 'concurrent-channel');
                $channel->push(true);
            });
        }

        for ($i = 0; $i < 49; ++$i) {
            $channel->pop(5);
        }

        // Final unsubscribe should vacate
        $result = $this->state->unsubscribe('app1', 'concurrent-channel');
        $this->assertTrue($result->channelVacated);
    }

    public function testFailedOpenDoesNotLeakConnectionSlot(): void
    {
        $this->state->acquireConnectionSlot('app1', 2);

        // Simulate a failed open — release the slot
        $this->state->releaseConnectionSlot('app1');

        // Both slots should be available
        $this->assertTrue($this->state->acquireConnectionSlot('app1', 2));
        $this->assertTrue($this->state->acquireConnectionSlot('app1', 2));
        $this->assertFalse($this->state->acquireConnectionSlot('app1', 2));
    }

    // ── Subscription count ────────────────────────────────────────────

    public function testSubscribeReturnsCorrectSubscriptionCount(): void
    {
        $result1 = $this->state->subscribe('app1', 'test-channel');
        $this->assertSame(1, $result1->subscriptionCount);

        $result2 = $this->state->subscribe('app1', 'test-channel');
        $this->assertSame(2, $result2->subscriptionCount);
    }

    public function testUnsubscribeReturnsCorrectSubscriptionCount(): void
    {
        $this->state->subscribe('app1', 'test-channel');
        $this->state->subscribe('app1', 'test-channel');

        $result = $this->state->unsubscribe('app1', 'test-channel');
        $this->assertSame(1, $result->subscriptionCount);

        $result = $this->state->unsubscribe('app1', 'test-channel');
        $this->assertSame(0, $result->subscriptionCount);
    }

    public function testGetSubscriptionCountReturnsZeroForUnknown(): void
    {
        $this->assertSame(0, $this->state->getSubscriptionCount('app1', 'nonexistent'));
    }

    public function testGetSubscriptionCountReturnsCurrentCount(): void
    {
        $this->state->subscribe('app1', 'test-channel');
        $this->state->subscribe('app1', 'test-channel');

        $this->assertSame(2, $this->state->getSubscriptionCount('app1', 'test-channel'));
    }

    // ── User subscription count ───────────────────────────────────────

    public function testGetUserSubscriptionCountReturnsZeroForUnknown(): void
    {
        $this->assertSame(0, $this->state->getUserSubscriptionCount('app1', 'presence-channel', 'unknown'));
    }

    public function testGetUserSubscriptionCountReturnsCurrentCount(): void
    {
        $this->state->subscribe('app1', 'presence-channel', 'user-1');
        $this->state->subscribe('app1', 'presence-channel', 'user-1');

        $this->assertSame(2, $this->state->getUserSubscriptionCount('app1', 'presence-channel', 'user-1'));
    }

    // ── Subscription count lock ───────────────────────────────────────

    public function testTrySubscriptionCountLockAcquiresOnFirstCall(): void
    {
        $this->assertTrue($this->state->trySubscriptionCountLock('app1', 'test-channel', 5000));
    }

    public function testTrySubscriptionCountLockFailsWithinTtl(): void
    {
        $this->assertTrue($this->state->trySubscriptionCountLock('app1', 'test-channel', 5000));
        $this->assertFalse($this->state->trySubscriptionCountLock('app1', 'test-channel', 5000));
    }

    public function testClearSubscriptionCountLockAllowsReacquire(): void
    {
        $this->assertTrue($this->state->trySubscriptionCountLock('app1', 'test-channel', 5000));

        $this->state->clearSubscriptionCountLock('app1', 'test-channel');

        $this->assertTrue($this->state->trySubscriptionCountLock('app1', 'test-channel', 5000));
    }

    // ── Cache miss lock ───────────────────────────────────────────────

    public function testTryCacheMissLockAcquiresOnFirstCall(): void
    {
        $this->assertTrue($this->state->tryCacheMissLock('app1', 'cache-channel', 10000));
    }

    public function testTryCacheMissLockFailsWithinTtl(): void
    {
        $this->assertTrue($this->state->tryCacheMissLock('app1', 'cache-channel', 10000));
        $this->assertFalse($this->state->tryCacheMissLock('app1', 'cache-channel', 10000));
    }

    public function testClearCacheMissLockAllowsReacquire(): void
    {
        $this->assertTrue($this->state->tryCacheMissLock('app1', 'cache-channel', 10000));

        $this->state->clearCacheMissLock('app1', 'cache-channel');

        $this->assertTrue($this->state->tryCacheMissLock('app1', 'cache-channel', 10000));
    }

    // ── Smoothing markers ─────────────────────────────────────────────

    public function testSetAndClearSmoothingPendingReturnsTrue(): void
    {
        $this->state->setSmoothingPending('app1', 'test-channel', 5000);

        $this->assertTrue($this->state->clearSmoothingPending('app1', 'test-channel', 5000));
    }

    public function testClearSmoothingPendingReturnsFalseWhenNoMarker(): void
    {
        $this->assertFalse($this->state->clearSmoothingPending('app1', 'test-channel', 5000));
    }

    public function testClearSmoothingPendingConsumesMarkerOnlyOnce(): void
    {
        $this->state->setSmoothingPending('app1', 'test-channel', 5000);

        $this->assertTrue($this->state->clearSmoothingPending('app1', 'test-channel', 5000));
        $this->assertFalse($this->state->clearSmoothingPending('app1', 'test-channel', 5000));
    }

    public function testSetAndClearMemberSmoothingPendingReturnsTrue(): void
    {
        $this->state->setMemberSmoothingPending('app1', 'presence-channel', 'user-1', 5000);

        $this->assertTrue($this->state->clearMemberSmoothingPending('app1', 'presence-channel', 'user-1', 5000));
    }

    public function testClearMemberSmoothingPendingReturnsFalseWhenNoMarker(): void
    {
        $this->assertFalse($this->state->clearMemberSmoothingPending('app1', 'presence-channel', 'user-1', 5000));
    }
}
