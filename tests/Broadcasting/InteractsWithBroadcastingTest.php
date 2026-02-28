<?php

declare(strict_types=1);

namespace Hypervel\Tests\Broadcasting;

use Hypervel\Broadcasting\BroadcastEvent;
use Hypervel\Broadcasting\Channel;
use Hypervel\Broadcasting\InteractsWithBroadcasting;
use Hypervel\Contracts\Broadcasting\Factory as BroadcastingFactory;
use Mockery as m;
use PHPUnit\Framework\TestCase;
use TypeError;

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
    protected function tearDown(): void
    {
        parent::tearDown();
    }

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

    public function testBroadcastViaWithIntBackedEnumStoresIntValue(): void
    {
        $event = new TestBroadcastingEvent();

        $event->broadcastVia(InteractsWithBroadcastingTestConnectionIntEnum::Connection1);

        // Int value is stored as-is (no cast to string) - will fail downstream if string expected
        $this->assertSame([1], $event->broadcastConnections());
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

    public function testBroadcastWithIntBackedEnumThrowsTypeErrorAtBroadcastTime(): void
    {
        $event = new TestBroadcastableEvent();
        $event->broadcastVia(InteractsWithBroadcastingTestConnectionIntEnum::Connection1);

        $broadcastEvent = new BroadcastEvent($event);
        $manager = m::mock(BroadcastingFactory::class);

        // TypeError is thrown when BroadcastManager::connection() receives int instead of ?string
        $this->expectException(TypeError::class);
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
