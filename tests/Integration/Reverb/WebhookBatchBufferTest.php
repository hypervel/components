<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Reverb;

use Hypervel\Foundation\Testing\Concerns\InteractsWithRedis;
use Hypervel\Reverb\Webhooks\WebhookBatchBuffer;
use Hypervel\Support\Facades\Redis;
use Hypervel\Testbench\TestCase;

/**
 * Integration tests for WebhookBatchBuffer against a real Redis server.
 *
 * @internal
 * @coversNothing
 */
class WebhookBatchBufferTest extends TestCase
{
    use InteractsWithRedis;

    protected WebhookBatchBuffer $buffer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buffer = new WebhookBatchBuffer(Redis::connection());
    }

    // ── appendAndCheckSchedule ────────────────────────────────────────

    public function testAppendAndCheckScheduleAcquiresLockOnFirstCall()
    {
        $result = $this->buffer->appendAndCheckSchedule('app1', ['name' => 'channel_occupied', 'channel' => 'test']);

        $this->assertTrue($result);
    }

    public function testAppendAndCheckScheduleReturnsFalseWhenLockAlreadyHeld()
    {
        $this->buffer->appendAndCheckSchedule('app1', ['name' => 'channel_occupied', 'channel' => 'test']);
        $result = $this->buffer->appendAndCheckSchedule('app1', ['name' => 'channel_vacated', 'channel' => 'test']);

        $this->assertFalse($result);

        // Both events should be in the buffer
        $this->assertTrue($this->buffer->hasRemaining('app1'));
    }

    // ── claim ─────────────────────────────────────────────────────────

    public function testClaimReturnsAccumulatedEvents()
    {
        for ($i = 0; $i < 5; ++$i) {
            $this->buffer->appendAndCheckSchedule('app1', ['name' => 'event_' . $i, 'channel' => 'test']);
        }

        $events = $this->buffer->claim('app1', 50, 262144);

        $this->assertCount(5, $events);
        $this->assertSame('event_0', $events[0]['name']);
        $this->assertSame('event_4', $events[4]['name']);
    }

    public function testClaimRespectsMaxEvents()
    {
        for ($i = 0; $i < 10; ++$i) {
            $this->buffer->appendAndCheckSchedule('app1', ['name' => 'event_' . $i]);
        }

        $events = $this->buffer->claim('app1', 5, 262144);

        $this->assertCount(5, $events);
        $this->assertTrue($this->buffer->hasRemaining('app1'));
    }

    public function testClaimRespectsMaxPayloadBytes()
    {
        // Each event is roughly 30-40 bytes as JSON
        for ($i = 0; $i < 10; ++$i) {
            $this->buffer->appendAndCheckSchedule('app1', ['name' => 'channel_occupied', 'channel' => 'test-channel-' . $i]);
        }

        // Set a very low byte limit — should only fit a few events
        $events = $this->buffer->claim('app1', 50, 300);

        $this->assertGreaterThan(0, count($events));
        $this->assertLessThan(10, count($events));
        $this->assertTrue($this->buffer->hasRemaining('app1'));
    }

    public function testClaimReturnsEmptyWhenBufferEmpty()
    {
        $events = $this->buffer->claim('app1', 50, 262144);

        $this->assertSame([], $events);
    }

    public function testClaimMovesEventsToProcessingHash()
    {
        $this->buffer->appendAndCheckSchedule('app1', ['name' => 'test_event']);

        $this->buffer->claim('app1', 50, 262144);

        // Processing hash should exist with claimed_at
        $claimedAt = Redis::connection()->hget('reverb:webhook:processing:app1', 'claimed_at');
        $this->assertNotNull($claimedAt);
    }

    public function testClaimBailsWhenProcessingKeyExists()
    {
        $this->buffer->appendAndCheckSchedule('app1', ['name' => 'event_1']);

        // First claim succeeds
        $events = $this->buffer->claim('app1', 50, 262144);
        $this->assertCount(1, $events);

        // Add more events
        $this->buffer->appendAndCheckSchedule('app1', ['name' => 'event_2']);

        // Second claim bails because processing key exists
        $events = $this->buffer->claim('app1', 50, 262144);
        $this->assertSame([], $events);
    }

    // ── acknowledge ───────────────────────────────────────────────────

    public function testAcknowledgeDeletesProcessingKey()
    {
        $this->buffer->appendAndCheckSchedule('app1', ['name' => 'test_event']);
        $this->buffer->claim('app1', 50, 262144);

        $this->buffer->acknowledge('app1');

        $exists = Redis::connection()->exists('reverb:webhook:processing:app1');
        $this->assertSame(0, $exists);
    }

    // ── recoverStaleProcessingKeys ────────────────────────────────────

    public function testRecoverStaleProcessingKeysRequeuesOldEvents()
    {
        // Manually create a stale processing hash
        Redis::connection()->hset('reverb:webhook:processing:app1', 'events', json_encode([
            '{"name":"channel_occupied","channel":"test"}',
        ]), 'claimed_at', (string) (time() - 120));

        $recovered = $this->buffer->recoverStaleProcessingKeys('app1', 60);

        $this->assertTrue($recovered);
        $this->assertTrue($this->buffer->hasRemaining('app1'));

        // Processing key should be deleted
        $exists = Redis::connection()->exists('reverb:webhook:processing:app1');
        $this->assertSame(0, $exists);
    }

    public function testRecoverStaleProcessingKeysIgnoresRecentKeys()
    {
        Redis::connection()->hset('reverb:webhook:processing:app1', 'events', json_encode([
            '{"name":"channel_occupied","channel":"test"}',
        ]), 'claimed_at', (string) (time() - 10));

        $recovered = $this->buffer->recoverStaleProcessingKeys('app1', 60);

        $this->assertFalse($recovered);
    }

    public function testRecoverStaleProcessingKeysNoopsWhenNoKey()
    {
        $recovered = $this->buffer->recoverStaleProcessingKeys('app1', 60);

        $this->assertFalse($recovered);
    }

    // ── clearFlushLock ────────────────────────────────────────────────

    public function testClearFlushLockAllowsNewSchedule()
    {
        $this->buffer->appendAndCheckSchedule('app1', ['name' => 'event_1']);
        // Lock is now held

        $this->buffer->clearFlushLock('app1');

        // Next append should acquire the lock again
        $result = $this->buffer->appendAndCheckSchedule('app1', ['name' => 'event_2']);
        $this->assertTrue($result);
    }

    // ── hasRemaining ──────────────────────────────────────────────────

    public function testHasRemainingReturnsTrueWhenItemsExist()
    {
        $this->buffer->appendAndCheckSchedule('app1', ['name' => 'test']);

        $this->assertTrue($this->buffer->hasRemaining('app1'));
    }

    public function testHasRemainingReturnsFalseWhenEmpty()
    {
        $this->assertFalse($this->buffer->hasRemaining('app1'));
    }
}
