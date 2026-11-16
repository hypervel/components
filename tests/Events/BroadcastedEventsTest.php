<?php

declare(strict_types=1);

namespace Hypervel\Tests\Events\BroadcastedEventsTest;

use Hypervel\Broadcasting\PendingBroadcast;
use Hypervel\Container\Container;
use Hypervel\Contracts\Broadcasting\Factory as BroadcastFactory;
use Hypervel\Contracts\Broadcasting\ShouldBroadcast;
use Hypervel\Events\Dispatcher;
use Hypervel\Foundation\Events\Dispatchable;
use Hypervel\Tests\Events\Fixtures\ExampleEvent;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use JMac\Testing\Matching\Argument;
use stdClass;

class BroadcastedEventsTest extends TestCase
{
    use VerifiesDoubles;

    /**
     * Create a dispatcher that exposes the protected broadcast check.
     */
    protected function exposedDispatcher(): Dispatcher
    {
        return new class extends Dispatcher {
            /**
             * Determine if the payload has a broadcastable event.
             */
            public function shouldBroadcast(array $payload): bool
            {
                return parent::shouldBroadcast($payload);
            }
        };
    }

    public function testShouldBroadcastSuccess(): void
    {
        $d = $this->exposedDispatcher();

        $event = new BroadcastEvent;

        $this->assertTrue($d->shouldBroadcast([$event]));

        $event = new AlwaysBroadcastEvent;

        $this->assertTrue($d->shouldBroadcast([$event]));
    }

    public function testShouldBroadcastAsQueuedAndCallNormalListeners(): void
    {
        unset($_SERVER['__event.test']);
        $container = Double::for(Container::class, override: true);
        $d = new Dispatcher($container->instance());
        $broadcast = Double::for(BroadcastFactory::class);
        $broadcast->expects('queue');
        $container->expects('make')->with(BroadcastFactory::class)->returns($broadcast);

        $d->listen(AlwaysBroadcastEvent::class, function (AlwaysBroadcastEvent $payload): void {
            $_SERVER['__event.test'] = $payload;
        });

        $d->dispatch($e = new AlwaysBroadcastEvent);

        $this->assertSame($e, $_SERVER['__event.test']);
    }

    public function testHasListenersReturnsFalseForBroadcastEventsWithoutNormalListeners(): void
    {
        $d = new Dispatcher;

        $this->assertFalse($d->hasListeners(AlwaysBroadcastEvent::class));
    }

    public function testShouldBroadcastFail(): void
    {
        $d = $this->exposedDispatcher();

        $event = new BroadcastFalseCondition;

        $this->assertFalse($d->shouldBroadcast([$event]));

        $event = new ExampleEvent;

        $this->assertFalse($d->shouldBroadcast([$event]));
    }

    public function testBroadcastWithMultipleChannels(): void
    {
        $container = Double::for(Container::class, override: true);
        $d = new Dispatcher($container->instance());
        $broadcast = Double::for(BroadcastFactory::class);
        $broadcast->expects('queue');
        $container->expects('make')->with(BroadcastFactory::class)->returns($broadcast);

        $event = new class implements ShouldBroadcast {
            public function broadcastOn(): array
            {
                return ['channel-1', 'channel-2'];
            }
        };

        $d->dispatch($event);
    }

    public function testBroadcastWithCustomConnectionName(): void
    {
        $container = Double::for(Container::class, override: true);
        $d = new Dispatcher($container->instance());
        $broadcast = Double::for(BroadcastFactory::class);
        $broadcast->expects('queue');
        $container->expects('make')->with(BroadcastFactory::class)->returns($broadcast);

        $event = new class implements ShouldBroadcast {
            public string $connection = 'custom-connection';

            public function broadcastOn(): array
            {
                return ['test-channel'];
            }
        };

        $d->dispatch($event);
    }

    public function testBroadcastWithCustomEventName(): void
    {
        $container = Double::for(Container::class, override: true);
        $d = new Dispatcher($container->instance());
        $broadcast = Double::for(BroadcastFactory::class);
        $broadcast->expects('queue');
        $container->expects('make')->with(BroadcastFactory::class)->returns($broadcast);

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

    public function testBroadcastWithCustomPayload(): void
    {
        $container = Double::for(Container::class, override: true);
        $d = new Dispatcher($container->instance());
        $broadcast = Double::for(BroadcastFactory::class);
        $broadcast->expects('queue');
        $container->expects('make')->with(BroadcastFactory::class)->returns($broadcast);

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

    public function testEventBroadcastsUsingNamedArguments(): void
    {
        $container = new Container;
        $broadcast = Double::for(BroadcastFactory::class);
        $container->instance(BroadcastFactory::class, $broadcast);

        $originalContainer = Container::getInstance();
        Container::setInstance($container);

        try {
            $pendingBroadcast = new PendingBroadcastWithoutDestructor(new Dispatcher, new stdClass);

            $broadcast->expects('event')
                ->with(Argument::satisfies(function (mixed $event): bool {
                    $this->assertInstanceOf(BroadcastableNamedArgumentsEvent::class, $event);
                    $this->assertSame('first-value', $event->first);
                    $this->assertSame('second-value', $event->second);

                    return true;
                }))
                ->returns($pendingBroadcast);

            $this->assertSame(
                $pendingBroadcast,
                BroadcastableNamedArgumentsEvent::broadcast(second: 'second-value', first: 'first-value')
            );
        } finally {
            Container::setInstance($originalContainer);
        }
    }
}

class PendingBroadcastWithoutDestructor extends PendingBroadcast
{
    /**
     * Prevent the fixture event from being dispatched.
     */
    public function __destruct()
    {
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

class BroadcastableNamedArgumentsEvent
{
    use Dispatchable;

    public function __construct(
        public string $first,
        public string $second,
    ) {
    }
}
