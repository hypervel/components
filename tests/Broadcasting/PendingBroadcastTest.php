<?php

declare(strict_types=1);

namespace Hypervel\Tests\Broadcasting;

use Hypervel\Broadcasting\InteractsWithBroadcasting;
use Hypervel\Broadcasting\PendingBroadcast;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Tests\TestCase;
use Mockery as m;

enum PendingBroadcastTestConnectionStringEnum: string
{
    case Log = 'log';
    case Pusher = 'pusher';
}

enum PendingBroadcastTestConnectionIntEnum: int
{
    case Connection1 = 1;
    case Connection2 = 2;
}

enum PendingBroadcastTestConnectionUnitEnum
{
    case redis;
    case ably;
}

class PendingBroadcastTest extends TestCase
{
    public function testViaAcceptsStringBackedEnum(): void
    {
        $dispatcher = m::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')->once();

        $event = new TestPendingBroadcastEvent;
        $pending = new PendingBroadcast($dispatcher, $event);

        $result = $pending->via(PendingBroadcastTestConnectionStringEnum::Pusher);

        $this->assertSame(['pusher'], $event->broadcastConnections());
        $this->assertSame($pending, $result);
    }

    public function testViaAcceptsUnitEnum(): void
    {
        $dispatcher = m::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')->once();

        $event = new TestPendingBroadcastEvent;
        $pending = new PendingBroadcast($dispatcher, $event);

        $pending->via(PendingBroadcastTestConnectionUnitEnum::redis);

        $this->assertSame(['redis'], $event->broadcastConnections());
    }

    public function testViaNormalizesIntegerBackedEnumImmediately(): void
    {
        $dispatcher = m::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')->once();

        $event = new TestPendingBroadcastEvent;
        $pending = new PendingBroadcast($dispatcher, $event);

        $pending->via(PendingBroadcastTestConnectionIntEnum::Connection1);

        $this->assertSame(['1'], $event->broadcastConnections());
    }

    public function testViaAcceptsNull(): void
    {
        $dispatcher = m::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')->once();

        $event = new TestPendingBroadcastEvent;
        $pending = new PendingBroadcast($dispatcher, $event);

        $pending->via(null);

        $this->assertSame([null], $event->broadcastConnections());
    }

    public function testViaAcceptsString(): void
    {
        $dispatcher = m::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')->once();

        $event = new TestPendingBroadcastEvent;
        $pending = new PendingBroadcast($dispatcher, $event);

        $pending->via('custom-connection');

        $this->assertSame(['custom-connection'], $event->broadcastConnections());
    }
}

class TestPendingBroadcastEvent
{
    use InteractsWithBroadcasting;
}
