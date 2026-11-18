<?php

declare(strict_types=1);

namespace Hypervel\Tests\Broadcasting;

use Hypervel\Broadcasting\BroadcastEvent;
use Hypervel\Broadcasting\Channel;
use Hypervel\Broadcasting\InteractsWithBroadcasting;
use Hypervel\Contracts\Broadcasting\Broadcaster;
use Hypervel\Contracts\Broadcasting\Factory as BroadcastingFactory;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;

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

class InteractsWithBroadcastingTest extends TestCase
{
    use VerifiesDoubles;

    public function testBroadcastViaAcceptsStringBackedEnum(): void
    {
        $event = new TestBroadcastingEvent;

        $event->broadcastVia(InteractsWithBroadcastingTestConnectionStringEnum::Pusher);

        $this->assertSame(['pusher'], $event->broadcastConnections());
    }

    public function testBroadcastViaAcceptsUnitEnum(): void
    {
        $event = new TestBroadcastingEvent;

        $event->broadcastVia(InteractsWithBroadcastingTestConnectionUnitEnum::redis);

        $this->assertSame(['redis'], $event->broadcastConnections());
    }

    public function testBroadcastViaNormalizesIntegerBackedEnums(): void
    {
        $event = new TestBroadcastingEvent;

        $event->broadcastVia(InteractsWithBroadcastingTestConnectionIntEnum::Connection1);

        $this->assertSame(['1'], $event->broadcastConnections());
    }

    public function testBroadcastViaNormalizesEveryArrayConnection(): void
    {
        $event = new TestBroadcastingEvent;

        $event->broadcastVia([
            InteractsWithBroadcastingTestConnectionIntEnum::Connection2,
            InteractsWithBroadcastingTestConnectionUnitEnum::ably,
            'custom',
        ]);

        $this->assertSame(['2', 'ably', 'custom'], $event->broadcastConnections());
    }

    public function testBroadcastViaAcceptsNull(): void
    {
        $event = new TestBroadcastingEvent;

        $event->broadcastVia(null);

        $this->assertSame([null], $event->broadcastConnections());
    }

    public function testBroadcastViaAcceptsString(): void
    {
        $event = new TestBroadcastingEvent;

        $event->broadcastVia('custom-connection');

        $this->assertSame(['custom-connection'], $event->broadcastConnections());
    }

    public function testBroadcastViaIsChainable(): void
    {
        $event = new TestBroadcastingEvent;

        $result = $event->broadcastVia('pusher');

        $this->assertSame($event, $result);
    }

    public function testBroadcastWithIntegerBackedEnumUsesNormalizedConnection(): void
    {
        $event = new TestBroadcastableEvent;
        $event->broadcastVia(InteractsWithBroadcastingTestConnectionIntEnum::Connection1);

        $broadcastEvent = new BroadcastEvent($event);
        $manager = Double::for(BroadcastingFactory::class)->strict();
        $broadcaster = Double::for(Broadcaster::class)->strict();

        $manager->expects('connection')->with('1')->returns($broadcaster);
        $broadcaster->expects('broadcast');

        $broadcastEvent->handle($manager);
    }
}

class TestBroadcastingEvent
{
    use InteractsWithBroadcasting;
}

class TestBroadcastableEvent
{
    use InteractsWithBroadcasting;

    public function broadcastOn(): Channel
    {
        return new Channel('test-channel');
    }
}
