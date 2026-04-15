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

    // ── Presence user tracking ─────────────────────────────────────────

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

    // ── Connection slots ───────────────────────────────────────────────

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

    // ── Key cleanup ────────────────────────────────────────────────────

    public function testUnsubscribeCleansUpZeroCountKeys()
    {
        $this->state->subscribe('app1', 'test-channel', 'user-1');
        $this->state->unsubscribe('app1', 'test-channel', 'user-1');

        // Re-subscribe — should get channelOccupied again since key was cleaned
        $result = $this->state->subscribe('app1', 'test-channel', 'user-1');

        $this->assertTrue($result->channelOccupied);
        $this->assertTrue($result->memberAdded);
    }

    // ── App isolation ──────────────────────────────────────────────────

    public function testDifferentAppsHaveIsolatedState()
    {
        $result1 = $this->state->subscribe('app1', 'test-channel');
        $result2 = $this->state->subscribe('app2', 'test-channel');

        $this->assertTrue($result1->channelOccupied);
        $this->assertTrue($result2->channelOccupied);
    }

    // ── Concurrency ────────────────────────────────────────────────────

    public function testConcurrentSubscribeUnsubscribeProducesCorrectCounts()
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
}
