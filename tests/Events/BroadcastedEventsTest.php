<?php

declare(strict_types=1);

namespace Hypervel\Tests\Events\BroadcastedEventsTest;

use Hypervel\Container\Container;
use Hypervel\Contracts\Broadcasting\Factory as BroadcastFactory;
use Hypervel\Contracts\Broadcasting\ShouldBroadcast;
use Hypervel\Events\Dispatcher;
use Hypervel\Tests\TestCase;
use Mockery as m;

/**
 * @internal
 * @coversNothing
 */
class BroadcastedEventsTest extends TestCase
{
    public function testShouldBroadcastSuccess()
    {
        $d = m::mock(Dispatcher::class);

        $d->makePartial()->shouldAllowMockingProtectedMethods();

        $event = new BroadcastEvent();

        $this->assertTrue($d->shouldBroadcast([$event]));

        $event = new AlwaysBroadcastEvent();

        $this->assertTrue($d->shouldBroadcast([$event]));
    }

    public function testShouldBroadcastAsQueuedAndCallNormalListeners()
    {
        unset($_SERVER['__event.test']);
        $d = new Dispatcher($container = m::mock(Container::class));
        $broadcast = m::mock(BroadcastFactory::class);
        $broadcast->shouldReceive('queue')->once();
        $container->shouldReceive('make')->once()->with(BroadcastFactory::class)->andReturn($broadcast);

        $d->listen(AlwaysBroadcastEvent::class, function ($payload) {
            $_SERVER['__event.test'] = $payload;
        });

        $d->dispatch($e = new AlwaysBroadcastEvent());

        $this->assertSame($e, $_SERVER['__event.test']);
    }

    public function testShouldBroadcastFail()
    {
        $d = m::mock(Dispatcher::class);

        $d->makePartial()->shouldAllowMockingProtectedMethods();

        $event = new BroadcastFalseCondition();

        $this->assertFalse($d->shouldBroadcast([$event]));

        $event = new ExampleEvent();

        $this->assertFalse($d->shouldBroadcast([$event]));
    }

    public function testBroadcastWithMultipleChannels()
    {
        $d = new Dispatcher($container = m::mock(Container::class));
        $broadcast = m::mock(BroadcastFactory::class);
        $broadcast->shouldReceive('queue')->once();
        $container->shouldReceive('make')->once()->with(BroadcastFactory::class)->andReturn($broadcast);

        $event = new class implements ShouldBroadcast {
            public function broadcastOn(): array
            {
                return ['channel-1', 'channel-2'];
            }
        };

        $d->dispatch($event);
    }

    public function testBroadcastWithCustomConnectionName()
    {
        $d = new Dispatcher($container = m::mock(Container::class));
        $broadcast = m::mock(BroadcastFactory::class);
        $broadcast->shouldReceive('queue')->once();
        $container->shouldReceive('make')->once()->with(BroadcastFactory::class)->andReturn($broadcast);

        $event = new class implements ShouldBroadcast {
            public string $connection = 'custom-connection';

            public function broadcastOn(): array
            {
                return ['test-channel'];
            }
        };

        $d->dispatch($event);
    }

    public function testBroadcastWithCustomEventName()
    {
        $d = new Dispatcher($container = m::mock(Container::class));
        $broadcast = m::mock(BroadcastFactory::class);
        $broadcast->shouldReceive('queue')->once();
        $container->shouldReceive('make')->once()->with(BroadcastFactory::class)->andReturn($broadcast);

        $event = new class implements ShouldBroadcast {
            public function broadcastOn(): array
            {
                return ['test-channel'];
            }

            public function broadcastAs(): string
            {
                return 'custom-event-name';
            }
        };

        $d->dispatch($event);
    }

    public function testBroadcastWithCustomPayload()
    {
        $d = new Dispatcher($container = m::mock(Container::class));
        $broadcast = m::mock(BroadcastFactory::class);
        $broadcast->shouldReceive('queue')->once();
        $container->shouldReceive('make')->once()->with(BroadcastFactory::class)->andReturn($broadcast);

        $event = new class implements ShouldBroadcast {
            public string $customData = 'test-data';

            public function broadcastOn(): array
            {
                return ['test-channel'];
            }

            public function broadcastWith(): array
            {
                return ['custom' => $this->customData];
            }
        };

        $d->dispatch($event);
    }
}

class BroadcastEvent implements ShouldBroadcast
{
    public function broadcastOn(): array
    {
        return ['test-channel'];
    }

    public function broadcastWhen(): bool
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
    public function broadcastWhen(): bool
    {
        return false;
    }
}

class ExampleEvent
{
}
