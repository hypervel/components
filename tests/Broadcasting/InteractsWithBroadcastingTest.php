<?php

declare(strict_types=1);

namespace Hypervel\Tests\Broadcasting;

use Hypervel\Broadcasting\InteractsWithBroadcasting;
use PHPUnit\Framework\TestCase;

enum InteractsWithBroadcastingTestConnectionStringEnum: string
{
    case Log = 'log';
    case Pusher = 'pusher';
}

enum InteractsWithBroadcastingTestConnectionIntEnum: int
{
    case Connection1 = 1;
    case Connection2 = 2;
}

enum InteractsWithBroadcastingTestConnectionUnitEnum
{
    case redis;
    case ably;
}

/**
 * @internal
 * @coversNothing
 */
class InteractsWithBroadcastingTest extends TestCase
{
    public function testBroadcastViaAcceptsStringBackedEnum(): void
    {
        $event = new TestBroadcastingEvent();

        $event->broadcastVia(InteractsWithBroadcastingTestConnectionStringEnum::Pusher);

        $this->assertSame(['pusher'], $event->broadcastConnections());
    }

    public function testBroadcastViaAcceptsUnitEnum(): void
    {
        $event = new TestBroadcastingEvent();

        $event->broadcastVia(InteractsWithBroadcastingTestConnectionUnitEnum::redis);

        $this->assertSame(['redis'], $event->broadcastConnections());
    }

    public function testBroadcastViaAcceptsIntBackedEnum(): void
    {
        $event = new TestBroadcastingEvent();

        $event->broadcastVia(InteractsWithBroadcastingTestConnectionIntEnum::Connection1);

        // Int value 1 should be cast to string '1'
        $this->assertSame(['1'], $event->broadcastConnections());
    }

    public function testBroadcastViaAcceptsNull(): void
    {
        $event = new TestBroadcastingEvent();

        $event->broadcastVia(null);

        $this->assertSame([null], $event->broadcastConnections());
    }

    public function testBroadcastViaAcceptsString(): void
    {
        $event = new TestBroadcastingEvent();

        $event->broadcastVia('custom-connection');

        $this->assertSame(['custom-connection'], $event->broadcastConnections());
    }

    public function testBroadcastViaIsChainable(): void
    {
        $event = new TestBroadcastingEvent();

        $result = $event->broadcastVia('pusher');

        $this->assertSame($event, $result);
    }
}

class TestBroadcastingEvent
{
    use InteractsWithBroadcasting;
}
