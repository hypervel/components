<?php

declare(strict_types=1);

namespace Hypervel\Tests\Broadcasting;

use Hypervel\Broadcasting\InteractsWithBroadcasting;
use Hypervel\Broadcasting\PendingBroadcast;
use Mockery as m;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;

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

/**
 * @internal
 * @coversNothing
 */
class PendingBroadcastTest extends TestCase
{
    protected function tearDown(): void
    {
        m::close();
    }

    public function testViaAcceptsStringBackedEnum(): void
    {
        $dispatcher = m::mock(EventDispatcherInterface::class);
        $dispatcher->shouldReceive('dispatch')->once();

        $event = new TestPendingBroadcastEvent();
        $pending = new PendingBroadcast($dispatcher, $event);

        $result = $pending->via(PendingBroadcastTestConnectionStringEnum::Pusher);

        $this->assertSame(['pusher'], $event->broadcastConnections());
        $this->assertSame($pending, $result);
    }

    public function testViaAcceptsUnitEnum(): void
    {
        $dispatcher = m::mock(EventDispatcherInterface::class);
        $dispatcher->shouldReceive('dispatch')->once();

        $event = new TestPendingBroadcastEvent();
        $pending = new PendingBroadcast($dispatcher, $event);

        $pending->via(PendingBroadcastTestConnectionUnitEnum::redis);

        $this->assertSame(['redis'], $event->broadcastConnections());
    }

    public function testViaAcceptsIntBackedEnum(): void
    {
        $dispatcher = m::mock(EventDispatcherInterface::class);
        $dispatcher->shouldReceive('dispatch')->once();

        $event = new TestPendingBroadcastEvent();
        $pending = new PendingBroadcast($dispatcher, $event);

        $pending->via(PendingBroadcastTestConnectionIntEnum::Connection1);

        // Int value 1 should be cast to string '1'
        $this->assertSame(['1'], $event->broadcastConnections());
    }

    public function testViaAcceptsNull(): void
    {
        $dispatcher = m::mock(EventDispatcherInterface::class);
        $dispatcher->shouldReceive('dispatch')->once();

        $event = new TestPendingBroadcastEvent();
        $pending = new PendingBroadcast($dispatcher, $event);

        $pending->via(null);

        $this->assertSame([null], $event->broadcastConnections());
    }

    public function testViaAcceptsString(): void
    {
        $dispatcher = m::mock(EventDispatcherInterface::class);
        $dispatcher->shouldReceive('dispatch')->once();

        $event = new TestPendingBroadcastEvent();
        $pending = new PendingBroadcast($dispatcher, $event);

        $pending->via('custom-connection');

        $this->assertSame(['custom-connection'], $event->broadcastConnections());
    }
}

class TestPendingBroadcastEvent
{
    use InteractsWithBroadcasting;
}
