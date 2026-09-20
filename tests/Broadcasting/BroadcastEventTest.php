<?php

declare(strict_types=1);

namespace Hypervel\Tests\Broadcasting;

use Exception;
use Hypervel\Broadcasting\BroadcastEvent;
use Hypervel\Broadcasting\InteractsWithBroadcasting;
use Hypervel\Contracts\Broadcasting\Broadcaster;
use Hypervel\Contracts\Broadcasting\Factory as BroadcastingFactory;
use Hypervel\Contracts\Broadcasting\ShouldBroadcast;
use Hypervel\Queue\Attributes\Backoff;
use Hypervel\Support\Collection;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Throwable;

class BroadcastEventTest extends TestCase
{
    public function testBasicEventBroadcastParameterFormatting(): void
    {
        $broadcaster = m::mock(Broadcaster::class);

        $broadcaster->expects('broadcast')->with(
            ['test-channel'],
            TestBroadcastEvent::class,
            ['firstName' => 'Taylor', 'lastName' => 'Otwell', 'collection' => ['foo' => 'bar']]
        );

        $manager = m::mock(BroadcastingFactory::class);

        $manager->expects('connection')->with(null)->andReturn($broadcaster);

        $event = new TestBroadcastEvent;

        (new BroadcastEvent($event))->handle($manager);
    }

    public function testManualParameterSpecification(): void
    {
        $broadcaster = m::mock(Broadcaster::class);

        $broadcaster->expects('broadcast')->with(
            ['test-channel'],
            TestBroadcastEventWithManualData::class,
            ['name' => 'Taylor', 'socket' => null]
        );

        $manager = m::mock(BroadcastingFactory::class);

        $manager->expects('connection')->with(null)->andReturn($broadcaster);

        $event = new TestBroadcastEventWithManualData;

        (new BroadcastEvent($event))->handle($manager);
    }

    public function testSpecificBroadcasterGiven(): void
    {
        $broadcaster = m::mock(Broadcaster::class);

        $broadcaster->expects('broadcast');

        $manager = m::mock(BroadcastingFactory::class);

        $manager->expects('connection')->with('log')->andReturn($broadcaster);

        $event = new TestBroadcastEventWithSpecificBroadcaster;

        (new BroadcastEvent($event))->handle($manager);
    }

    public function testSpecificChannelsPerConnection(): void
    {
        $broadcaster = m::mock(Broadcaster::class);

        $broadcaster->expects('broadcast')->with(
            ['first-channel'],
            TestBroadcastEventWithChannelsPerConnection::class,
            ['firstName' => 'Taylor', 'lastName' => 'Otwell', 'collection' => ['foo' => 'bar']]
        );

        $broadcaster->expects('broadcast')->with(
            ['second-channel'],
            TestBroadcastEventWithChannelsPerConnection::class,
            ['firstName' => 'Taylor']
        );

        $manager = m::mock(BroadcastingFactory::class);

        $manager->expects('connection')->with('first_connection')->andReturn($broadcaster);
        $manager->expects('connection')->with('second_connection')->andReturn($broadcaster);

        $event = new TestBroadcastEventWithChannelsPerConnection;

        (new BroadcastEvent($event))->handle($manager);
    }

    public function testBroadcastAsStringIsUsedAsEventName(): void
    {
        $this->assertEventBroadcastsAs(
            new TestBroadcastEventWithStringName,
            'custom-name',
        );
    }

    public function testBroadcastAsBackedEnumResolvesToValue(): void
    {
        $this->assertEventBroadcastsAs(
            new TestBroadcastEventWithEnumName,
            'custom-enum-name',
        );
    }

    public function testBroadcastAsIntegerBackedEnumZeroResolvesToStringValue(): void
    {
        $this->assertEventBroadcastsAs(
            new TestBroadcastEventWithIntegerEnumName,
            '0',
        );
    }

    public function testBroadcastAsUnitEnumResolvesToName(): void
    {
        $this->assertEventBroadcastsAs(
            new TestBroadcastEventWithUnitEnumName,
            'Custom',
        );
    }

    public function testSingleStringChannelIsBroadcast(): void
    {
        $broadcaster = m::mock(Broadcaster::class);
        $broadcaster->expects('broadcast')
            ->with(['test-channel'], TestBroadcastEventWithStringChannel::class, m::type('array'));

        $manager = m::mock(BroadcastingFactory::class);
        $manager->expects('connection')->with(null)->andReturn($broadcaster);

        (new BroadcastEvent(new TestBroadcastEventWithStringChannel))->handle($manager);
    }

    public function testCloningPreservesEnumEventIdentity(): void
    {
        $job = new BroadcastEvent(TestBroadcastEventName::Custom);

        $clone = clone $job;

        $this->assertSame($job->event, $clone->event);
    }

    public function testCloningIsolatesOrdinaryEventObjects(): void
    {
        $job = new BroadcastEvent(new TestBroadcastEvent);

        $clone = clone $job;

        $this->assertNotSame($job->event, $clone->event);
    }

    public function testMiddlewareProxiesMiddlewareFromUnderlyingEvent(): void
    {
        $event = new class {
            /**
             * Get the middleware for the event.
             */
            public function middleware(): array
            {
                return ['foo', 'bar'];
            }
        };

        $job = new BroadcastEvent($event);

        $this->assertSame(['foo', 'bar'], $job->middleware());
    }

    public function testMiddlewareProxiesFailedHandlerFromUnderlyingEvent(): void
    {
        $event = new class {
            /**
             * Handle a job failure.
             */
            public function failed(?Throwable $e = null): void
            {
                $e->validateCall();
            }
        };

        $job = new BroadcastEvent($event);

        $exception = m::mock(Exception::class);
        $exception->expects('validateCall');

        $job->failed($exception);
    }

    public function testDeleteWhenMissingModelsDefaultsToTrue(): void
    {
        $event = new TestBroadcastEvent;

        $job = new BroadcastEvent($event);

        $this->assertTrue($job->deleteWhenMissingModels);
    }

    public function testDeletingWhenMissingModelsCanBeDisabled(): void
    {
        $event = new class {
            public bool $deleteWhenMissingModels = false;
        };

        $job = new BroadcastEvent($event);

        $this->assertFalse($job->deleteWhenMissingModels);
    }

    public function testArrayBackoffIsReadFromTheUnderlyingEvent(): void
    {
        $job = new BroadcastEvent(new TestBroadcastEventWithArrayBackoff);

        $this->assertSame([1, 5, 10], $job->backoff);
    }

    public function testVariadicBackoffIsReadFromTheUnderlyingEvent(): void
    {
        $job = new BroadcastEvent(new TestBroadcastEventWithVariadicBackoff);

        $this->assertSame([1, 5, 10], $job->backoff);
    }

    /**
     * Assert an event uses the expected broadcast name.
     */
    protected function assertEventBroadcastsAs(object $event, string $name): void
    {
        $broadcaster = m::mock(Broadcaster::class);
        $broadcaster->expects('broadcast')->with(
            ['test-channel'],
            $name,
            ['firstName' => 'Taylor', 'lastName' => 'Otwell', 'collection' => ['foo' => 'bar']]
        );

        $manager = m::mock(BroadcastingFactory::class);
        $manager->expects('connection')->with(null)->andReturn($broadcaster);

        (new BroadcastEvent($event))->handle($manager);
    }
}

class TestBroadcastEvent
{
    public string $firstName = 'Taylor';

    public string $lastName = 'Otwell';

    public ?Collection $collection = null;

    private string $title = 'Developer';

    /**
     * Create a new event instance.
     */
    public function __construct()
    {
        $this->collection = collect(['foo' => 'bar']);
    }

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): array|string
    {
        return ['test-channel'];
    }
}

class TestBroadcastEventWithStringName extends TestBroadcastEvent
{
    /**
     * Get the broadcast event name.
     */
    public function broadcastAs(): string
    {
        return 'custom-name';
    }
}

class TestBroadcastEventWithEnumName extends TestBroadcastEvent
{
    /**
     * Get the broadcast event name.
     */
    public function broadcastAs(): TestBroadcastEventName
    {
        return TestBroadcastEventName::Custom;
    }
}

class TestBroadcastEventWithIntegerEnumName extends TestBroadcastEvent
{
    /**
     * Get the broadcast event name.
     */
    public function broadcastAs(): TestBroadcastIntegerEventName
    {
        return TestBroadcastIntegerEventName::Zero;
    }
}

class TestBroadcastEventWithUnitEnumName extends TestBroadcastEvent
{
    /**
     * Get the broadcast event name.
     */
    public function broadcastAs(): TestBroadcastUnitEventName
    {
        return TestBroadcastUnitEventName::Custom;
    }
}

class TestBroadcastEventWithStringChannel extends TestBroadcastEvent implements ShouldBroadcast
{
    /**
     * Get the channel the event should broadcast on.
     */
    public function broadcastOn(): string
    {
        return 'test-channel';
    }
}

enum TestBroadcastEventName: string
{
    case Custom = 'custom-enum-name';
}

enum TestBroadcastIntegerEventName: int
{
    case Zero = 0;
}

enum TestBroadcastUnitEventName
{
    case Custom;
}

class TestBroadcastEventWithManualData extends TestBroadcastEvent
{
    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return ['name' => 'Taylor'];
    }
}

class TestBroadcastEventWithSpecificBroadcaster extends TestBroadcastEvent
{
    use InteractsWithBroadcasting;

    /**
     * Create a new event instance.
     */
    public function __construct()
    {
        $this->broadcastVia('log');
    }
}

class TestBroadcastEventWithChannelsPerConnection extends TestBroadcastEvent
{
    /**
     * Get the connections to broadcast on.
     */
    public function broadcastConnections(): array
    {
        return [
            'first_connection',
            'second_connection',
        ];
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'first_connection' => [
                'firstName' => 'Taylor',
                'lastName' => 'Otwell',
                'collection' => ['foo' => 'bar'],
            ],
            'second_connection' => [
                'firstName' => 'Taylor',
            ],
        ];
    }

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): array
    {
        return [
            'first_connection' => ['first-channel'],
            'second_connection' => ['second-channel'],
        ];
    }
}

#[Backoff([1, 5, 10])]
class TestBroadcastEventWithArrayBackoff extends TestBroadcastEvent
{
}

#[Backoff(1, 5, 10)]
class TestBroadcastEventWithVariadicBackoff extends TestBroadcastEvent
{
}
