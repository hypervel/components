<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Reverb;

use Hypervel\Foundation\Testing\Concerns\InteractsWithRedis;
use Hypervel\Reverb\Webhooks\WebhookBatchBuffer;
use Hypervel\Support\Facades\Redis;
use Hypervel\Testbench\TestCase;

/**
 * Integration tests for WebhookBatchBuffer against a real Redis server.
 */
class WebhookBatchBufferTest extends TestCase
{
    use InteractsWithRedis;

    protected WebhookBatchBuffer $buffer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buffer = new WebhookBatchBufferProbe(Redis::connection());
    }

    // ── appendAndCheckSchedule ────────────────────────────────────────

    public function testAppendAndCheckScheduleAcquiresLockOnFirstCall(): void
    {
        $result = $this->buffer->appendAndCheckSchedule('app1', ['name' => 'channel_occupied', 'channel' => 'test']);

        $this->assertTrue($result);
    }

    public function testAppendAndCheckScheduleReturnsFalseWhenLockAlreadyHeld(): void
    {
        $this->buffer->appendAndCheckSchedule('app1', ['name' => 'channel_occupied', 'channel' => 'test']);
        $result = $this->buffer->appendAndCheckSchedule('app1', ['name' => 'channel_vacated', 'channel' => 'test']);

        $this->assertFalse($result);

        // Both events should be in the buffer
        $this->assertTrue($this->buffer->hasRemaining('app1'));
    }

    // ── claim ─────────────────────────────────────────────────────────

    public function testClaimReturnsAccumulatedEvents(): void
    {
        for ($i = 0; $i < 5; ++$i) {
            $this->buffer->appendAndCheckSchedule('app1', ['name' => 'event_' . $i, 'channel' => 'test']);
        }

        $events = $this->buffer->claim('app1', 50, 262144);

        $this->assertCount(5, $events);
        $this->assertSame('event_0', $events[0]['name']);
        $this->assertSame('event_4', $events[4]['name']);
    }

    public function testClaimRespectsMaxEvents(): void
    {
        for ($i = 0; $i < 10; ++$i) {
            $this->buffer->appendAndCheckSchedule('app1', ['name' => 'event_' . $i]);
        }

        $events = $this->buffer->claim('app1', 5, 262144);

        $this->assertCount(5, $events);
        $this->assertTrue($this->buffer->hasRemaining('app1'));
    }

    public function testClaimRespectsMaxPayloadBytes(): void
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

    public function testClaimReturnsEmptyWhenBufferEmpty(): void
    {
        $events = $this->buffer->claim('app1', 50, 262144);

        $this->assertSame([], $events);
    }

    public function testClaimMovesEventsToProcessingHash(): void
    {
        $this->buffer->appendAndCheckSchedule('app1', ['name' => 'test_event']);

        $this->buffer->claim('app1', 50, 262144);

        // Processing hash should exist with claimed_at
        $claimedAt = Redis::connection()->hget($this->key('app1', 'processing'), 'claimed_at');
        $this->assertNotNull($claimedAt);
    }

    public function testClaimBailsWhenProcessingKeyExists(): void
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

    public function testAcknowledgeDeletesProcessingKey(): void
    {
        $this->buffer->appendAndCheckSchedule('app1', ['name' => 'test_event']);
        $this->buffer->claim('app1', 50, 262144);

        $this->buffer->acknowledge('app1');

        $exists = Redis::connection()->exists($this->key('app1', 'processing'));
        $this->assertSame(0, $exists);
    }

    // ── recoverStaleProcessingKeys ────────────────────────────────────

    public function testRecoverStaleProcessingKeysRequeuesOldEvents(): void
    {
        // Manually create a stale processing hash
        Redis::connection()->hset($this->key('app1', 'processing'), 'events', json_encode([
            '{"name":"channel_occupied","channel":"test"}',
        ]), 'claimed_at', (string) (time() - 120));

        $recovered = $this->buffer->recoverStaleProcessingKeys('app1', 60);

        $this->assertTrue($recovered);
        $this->assertTrue($this->buffer->hasRemaining('app1'));

        // Processing key should be deleted
        $exists = Redis::connection()->exists($this->key('app1', 'processing'));
        $this->assertSame(0, $exists);
    }

    public function testRecoverStaleProcessingKeysIgnoresRecentKeys(): void
    {
        Redis::connection()->hset($this->key('app1', 'processing'), 'events', json_encode([
            '{"name":"channel_occupied","channel":"test"}',
        ]), 'claimed_at', (string) (time() - 10));

        $recovered = $this->buffer->recoverStaleProcessingKeys('app1', 60);

        $this->assertFalse($recovered);
    }

    public function testRecoverStaleProcessingKeysNoopsWhenNoKey(): void
    {
        $recovered = $this->buffer->recoverStaleProcessingKeys('app1', 60);

        $this->assertFalse($recovered);
    }

    // ── clearFlushLock ────────────────────────────────────────────────

    public function testClearFlushLockAllowsNewSchedule(): void
    {
        $this->buffer->appendAndCheckSchedule('app1', ['name' => 'event_1']);
        // Lock is now held

        $this->buffer->clearFlushLock('app1');

        // Next append should acquire the lock again
        $result = $this->buffer->appendAndCheckSchedule('app1', ['name' => 'event_2']);
        $this->assertTrue($result);
    }

    // ── hasRemaining ──────────────────────────────────────────────────

    public function testHasRemainingReturnsTrueWhenItemsExist(): void
    {
        $this->buffer->appendAndCheckSchedule('app1', ['name' => 'test']);

        $this->assertTrue($this->buffer->hasRemaining('app1'));
    }

    public function testHasRemainingReturnsFalseWhenEmpty(): void
    {
        $this->assertFalse($this->buffer->hasRemaining('app1'));
    }

    public function testHostileApplicationIdsProduceDistinctKeysInOneClusterSlot(): void
    {
        $keys = $this->probe()->keysForTest('tenant}{one');
        $otherKeys = $this->probe()->keysForTest('tenant}{two');

        $this->assertCount(1, array_unique(array_map($this->clusterTag(...), $keys)));
        $this->assertNotSame($this->clusterTag($keys['buffer']), $this->clusterTag($otherKeys['buffer']));
        $this->assertStringNotContainsString('tenant}{one', implode('', $keys));
    }

    private function key(string $appId, string $type): string
    {
        return $this->probe()->keysForTest($appId)[$type];
    }

    private function probe(): WebhookBatchBufferProbe
    {
        /** @var WebhookBatchBufferProbe */
        return $this->buffer;
    }

    private function clusterTag(string $key): string
    {
        preg_match('/\{([^}]*)\}/', $key, $matches);

        return $matches[1];
    }
}

class WebhookBatchBufferProbe extends WebhookBatchBuffer
{
    /**
     * @return array{buffer: string, flush: string, processing: string}
     */
    public function keysForTest(string $appId): array
    {
        $tag = $this->appHashTag($appId);

        return [
            'buffer' => "reverb:webhook:{{$tag}}:buffer",
            'flush' => "reverb:webhook:{{$tag}}:flush",
            'processing' => "reverb:webhook:{{$tag}}:processing",
        ];
    }
}
