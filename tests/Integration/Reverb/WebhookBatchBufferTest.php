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

        $payload = $this->buffer->claim('app1', 50, 262144)['payload'];

        $this->assertCount(5, $payload->events);
        $this->assertSame('event_0', $payload->events[0]['name']);
        $this->assertSame('event_4', $payload->events[4]['name']);
        $this->assertSame(36, strlen($payload->webhookId));
        $this->assertGreaterThan(0, $payload->timeMs);
    }

    public function testClaimRespectsMaxEvents(): void
    {
        for ($i = 0; $i < 10; ++$i) {
            $this->buffer->appendAndCheckSchedule('app1', ['name' => 'event_' . $i]);
        }

        $batch = $this->buffer->claim('app1', 5, 262144);

        $this->assertCount(5, $batch['payload']->events);
        $this->assertTrue($this->buffer->hasRemaining('app1'));
    }

    public function testClaimRespectsMaxPayloadBytes(): void
    {
        // Each event is roughly 30-40 bytes as JSON
        for ($i = 0; $i < 10; ++$i) {
            $this->buffer->appendAndCheckSchedule('app1', ['name' => 'channel_occupied', 'channel' => 'test-channel-' . $i]);
        }

        // Set a very low byte limit — should only fit a few events
        $events = $this->buffer->claim('app1', 50, 300)['payload']->events;

        $this->assertGreaterThan(0, count($events));
        $this->assertLessThan(10, count($events));
        $this->assertTrue($this->buffer->hasRemaining('app1'));
    }

    public function testClaimsKeepTheBufferOrderWhenAnEventExceedsTheByteBudget(): void
    {
        $this->buffer->appendAndCheckSchedule('app1', ['name' => 'first']);
        $this->buffer->appendAndCheckSchedule('app1', ['name' => 'second', 'data' => str_repeat('x', 200)]);
        $this->buffer->appendAndCheckSchedule('app1', ['name' => 'third']);

        $claimed = [];

        while ($batch = $this->buffer->claim('app1', 50, 150)) {
            $claimed[] = array_column($batch['payload']->events, 'name');
            $this->buffer->acknowledge('app1', $batch['token']);
        }

        // The oversized second event is still claimed on its own, so the buffer progresses.
        $this->assertSame([['first'], ['second'], ['third']], $claimed);
    }

    public function testClaimReturnsNullWhenBufferEmpty(): void
    {
        $this->assertNull($this->buffer->claim('app1', 50, 262144));
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
        $batch = $this->buffer->claim('app1', 50, 262144);
        $this->assertCount(1, $batch['payload']->events);

        // Add more events
        $this->buffer->appendAndCheckSchedule('app1', ['name' => 'event_2']);

        // Second claim bails because the first claim is current
        $this->assertNull($this->buffer->claim('app1', 50, 262144));
    }

    public function testStaleClaimIsTakenOverWithTheSameBatch(): void
    {
        $this->buffer->appendAndCheckSchedule('app1', ['name' => 'event_1']);
        $this->buffer->appendAndCheckSchedule('app1', ['name' => 'event_2']);
        $stalled = $this->buffer->claim('app1', 50, 262144);
        $this->buffer->appendAndCheckSchedule('app1', ['name' => 'event_3']);
        $this->expireClaim('app1');

        $takeover = $this->buffer->claim('app1', 50, 262144);

        $this->assertSame($stalled['payload']->toJson(), $takeover['payload']->toJson());
        $this->assertNotSame($stalled['token'], $takeover['token']);
        $this->assertTrue($this->buffer->hasRemaining('app1'));

        // The stalled flush finishing later must not delete the newer claim.
        $this->buffer->acknowledge('app1', $stalled['token']);
        $this->assertSame(1, Redis::connection()->exists($this->key('app1', 'processing')));

        $this->buffer->acknowledge('app1', $takeover['token']);
        $this->assertSame(0, Redis::connection()->exists($this->key('app1', 'processing')));
        $this->assertSame(['event_3'], array_column($this->buffer->claim('app1', 50, 262144)['payload']->events, 'name'));
    }

    // ── acknowledge ───────────────────────────────────────────────────

    public function testAcknowledgeDeletesProcessingKey(): void
    {
        $this->buffer->appendAndCheckSchedule('app1', ['name' => 'test_event']);
        $batch = $this->buffer->claim('app1', 50, 262144);

        $this->buffer->acknowledge('app1', $batch['token']);

        $exists = Redis::connection()->exists($this->key('app1', 'processing'));
        $this->assertSame(0, $exists);
    }

    // ── shouldScheduleFlush ───────────────────────────────────────────

    public function testShouldScheduleFlushOnceForAStaleClaim(): void
    {
        $this->buffer->appendAndCheckSchedule('app1', ['name' => 'test_event']);
        $this->buffer->claim('app1', 50, 262144);
        $this->buffer->clearFlushLock('app1');
        $this->expireClaim('app1');

        $this->assertTrue($this->buffer->shouldScheduleFlush('app1'));
        $this->assertFalse($this->buffer->shouldScheduleFlush('app1'));
        $this->assertGreaterThan(0, Redis::connection()->pttl($this->key('app1', 'flush')));

        // A lost dispatch is scheduled again once the key is gone.
        $this->buffer->clearFlushLock('app1');
        $this->assertTrue($this->buffer->shouldScheduleFlush('app1'));
    }

    public function testShouldScheduleFlushOnceForUnclaimedEvents(): void
    {
        $this->buffer->appendAndCheckSchedule('app1', ['name' => 'test_event']);
        // A failed flush dispatch clears the key it acquired.
        $this->buffer->clearFlushLock('app1');

        $this->assertTrue($this->buffer->shouldScheduleFlush('app1'));
        $this->assertFalse($this->buffer->shouldScheduleFlush('app1'));
    }

    public function testShouldNotScheduleFlushForACurrentClaimOrAnEmptyBuffer(): void
    {
        $this->assertFalse($this->buffer->shouldScheduleFlush('app1'));

        $this->buffer->appendAndCheckSchedule('app1', ['name' => 'event_1']);
        $this->buffer->claim('app1', 50, 262144);
        $this->buffer->appendAndCheckSchedule('app1', ['name' => 'event_2']);
        $this->buffer->clearFlushLock('app1');

        $this->assertFalse($this->buffer->shouldScheduleFlush('app1'));
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

    private function expireClaim(string $appId): void
    {
        Redis::connection()->hset($this->key($appId, 'processing'), 'claimed_at', (string) (time() - 120));
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
