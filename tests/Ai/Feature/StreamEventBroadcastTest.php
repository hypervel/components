<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature;

use Hypervel\Ai\Streaming\Events\TextDelta;
use Hypervel\Broadcasting\AnonymousEvent;
use Hypervel\Broadcasting\Channel;
use Hypervel\Support\Facades\Event;
use Hypervel\Testbench\TestCase;

class StreamEventBroadcastTest extends TestCase
{
    public function testStreamEventsBroadcastImmediatelyUnlessQueueingIsRequested(): void
    {
        Event::fake([AnonymousEvent::class]);

        $channel = new Channel('conversation');
        $event = new TextDelta('event', 'message', 'Hello', 1);
        $event->broadcast($channel);
        $event->broadcast($channel, now: false);

        $broadcasts = Event::dispatched(AnonymousEvent::class);

        $this->assertCount(2, $broadcasts);
        $this->assertTrue($broadcasts[0][0]->shouldBroadcastNow());
        $this->assertFalse($broadcasts[1][0]->shouldBroadcastNow());
        $this->assertSame([$channel], $broadcasts[0][0]->broadcastOn());
        $this->assertSame('text_delta', $broadcasts[0][0]->broadcastAs());
        $this->assertSame($event->toArray(), $broadcasts[0][0]->broadcastWith());
    }
}
