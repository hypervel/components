<?php

declare(strict_types=1);

namespace Hypervel\Tests\Event;

use Hypervel\Contracts\Broadcasting\Factory as BroadcastFactory;
use Hypervel\Contracts\Broadcasting\ShouldBroadcast;
use Hypervel\Event\EventDispatcher;
use Hypervel\Event\ListenerProvider;
use Mockery as m;
use PHPUnit\Framework\TestCase;
use Hypervel\Contracts\Container\Container;

/**
 * @internal
 * @coversNothing
 */
class BroadcastedEventsTest extends TestCase
{
    public function testShouldBroadcastSuccess()
    {
        $d = m::mock(EventDispatcher::class);

        $d->makePartial()->shouldAllowMockingProtectedMethods();

        $event = new BroadcastEvent();

        $this->assertTrue($d->shouldBroadcast($event));

        $event = new AlwaysBroadcastEvent();

        $this->assertTrue($d->shouldBroadcast($event));
    }

    public function testShouldBroadcastAsQueuedAndCallNormalListeners()
    {
        unset($_SERVER['__event.test']);
        $broadcast = m::mock(BroadcastFactory::class);
        $broadcast->shouldReceive('queue')->once();
        $container = m::mock(Container::class);
        $container->shouldReceive('get')->once()->with(BroadcastFactory::class)->andReturn($broadcast);
        $d = new EventDispatcher(new ListenerProvider(), null, $container);

        $d->listen(AlwaysBroadcastEvent::class, function ($payload) {
            $_SERVER['__event.test'] = $payload;
        });

        $d->dispatch($e = new AlwaysBroadcastEvent());

        $this->assertSame($e, $_SERVER['__event.test']);
    }

    public function testShouldBroadcastFail()
    {
        $d = m::mock(EventDispatcher::class);

        $d->makePartial()->shouldAllowMockingProtectedMethods();

        $event = new BroadcastFalseCondition();

        $this->assertFalse($d->shouldBroadcast($event));

        $event = new ExampleEvent();

        $this->assertFalse($d->shouldBroadcast($event));
    }
}

class BroadcastEvent implements ShouldBroadcast
{
    public function broadcastOn(): array
    {
        return ['test-channel'];
    }

    public function broadcastWhen()
    {
        return true;
    }
}

class AlwaysBroadcastEvent implements ShouldBroadcast
{
    public function broadcastOn(): array
    {
        return ['test-channel'];
    }
}

class BroadcastFalseCondition extends BroadcastEvent
{
    public function broadcastWhen()
    {
        return false;
    }
}
