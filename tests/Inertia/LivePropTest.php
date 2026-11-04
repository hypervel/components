<?php

declare(strict_types=1);

namespace Hypervel\Tests\Inertia\LivePropTest;

use Hypervel\Broadcasting\Broadcasters\Broadcaster;
use Hypervel\Broadcasting\Channel;
use Hypervel\Broadcasting\EncryptedPrivateChannel;
use Hypervel\Broadcasting\InteractsWithBroadcasting;
use Hypervel\Broadcasting\PresenceChannel;
use Hypervel\Broadcasting\PrivateChannel;
use Hypervel\Contracts\Broadcasting\HasBroadcastChannel;
use Hypervel\Http\JsonResponse;
use Hypervel\Http\Request;
use Hypervel\Inertia\AlwaysProp;
use Hypervel\Inertia\DeferProp;
use Hypervel\Inertia\Inertia;
use Hypervel\Inertia\LiveProp;
use Hypervel\Inertia\MergeProp;
use Hypervel\Inertia\OnceProp;
use Hypervel\Inertia\OptionalProp;
use Hypervel\Inertia\Response;
use Hypervel\Inertia\ScrollProp;
use Hypervel\Inertia\SupportsLiveUpdates;
use Hypervel\Tests\Inertia\TestCase;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Swoole\Coroutine\CanceledException;
use Throwable;

class OrderUpdated
{
    /**
     * Create a new event instance.
     */
    public function __construct(public int $id = 1)
    {
    }

    /**
     * Get the channel the event should broadcast on.
     */
    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('orders.' . $this->id);
    }
}

class UserUpdated
{
    /**
     * Create a new event instance.
     */
    public function __construct(public int $id = 7)
    {
    }

    /**
     * Get the channel the event should broadcast on.
     */
    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('users.' . $this->id);
    }
}

class OrderPaid
{
    /**
     * Get the channel the event should broadcast on.
     */
    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('orders.1');
    }
}

class OrderShipped
{
    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [new Channel('stats'), new PresenceChannel('room.1')];
    }
}

class OrderCreated
{
    /**
     * Get the channel the event should broadcast on.
     */
    public function broadcastOn(): Channel
    {
        return new Channel('orders');
    }

    /**
     * Get the event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'order.created';
    }
}

class OrderArchived
{
    /**
     * Get the channel the event should broadcast on.
     */
    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('orders');
    }

    /**
     * Get the event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'order.archived';
    }
}

class OrderRestored
{
    /**
     * Get the channel the event should broadcast on.
     */
    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('orders');
    }
}

class OrderRenamed
{
    /**
     * Create a new event instance.
     */
    public function __construct(public string $name = 'order')
    {
    }

    /**
     * Get the channel the event should broadcast on.
     */
    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('orders');
    }

    /**
     * Get the event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return "{$this->name}.renamed";
    }
}

enum OrderEvent: string
{
    case Refunded = 'order.refunded';
}

class OrderRefunded
{
    /**
     * Get the channel the event should broadcast on.
     */
    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('orders.1');
    }

    /**
     * Get the event's broadcast name.
     */
    public function broadcastAs(): OrderEvent
    {
        return OrderEvent::Refunded;
    }
}

enum OrderEventCode: int
{
    case Escalated = 42;
}

class OrderEscalated
{
    /**
     * Get the channel the event should broadcast on.
     */
    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('orders.1');
    }

    /**
     * Get the event's broadcast name.
     */
    public function broadcastAs(): OrderEventCode
    {
        return OrderEventCode::Escalated;
    }
}

class OrderNoted
{
    /**
     * @var null|object{id: int}
     */
    public ?object $order = null;

    /**
     * Get the channel the event should broadcast on.
     */
    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('orders.' . $this->order->id);
    }
}

class OrderBroadcastPerConnection
{
    /**
     * Get the channels the event should broadcast on, keyed by connection.
     *
     * @return array<string, array<int, Channel>>
     */
    public function broadcastOn(): array
    {
        return [
            'reverb' => [new PrivateChannel('orders.1')],
            'pusher' => [new PrivateChannel('pusher.orders.1')],
        ];
    }

    /**
     * Get the broadcaster connections the event should be broadcast on.
     *
     * The broadcast job only sends a connection its named channels when the event is
     * broadcast via that connection, which upstream's fixture leaves out.
     *
     * @return array<int, string>
     */
    public function broadcastConnections(): array
    {
        return ['reverb', 'pusher'];
    }
}

class OrderBroadcastOnUnnamedOrDefaultConnection
{
    use InteractsWithBroadcasting;

    /**
     * Get the channels the event should broadcast on, keyed by connection.
     *
     * @return array<string, array<int, Channel>>
     */
    public function broadcastOn(): array
    {
        return [
            '' => [new PrivateChannel('orders.1')],
            'reverb' => [new PrivateChannel('reverb.orders.1')],
            'pusher' => [new PrivateChannel('pusher.orders.1')],
        ];
    }
}

class OrderSecured
{
    /**
     * Get the channel the event should broadcast on.
     */
    public function broadcastOn(): EncryptedPrivateChannel
    {
        return new EncryptedPrivateChannel('orders.1');
    }
}

class OrderWithoutChannels
{
}

class OrderWithEmptyChannels
{
    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [];
    }
}

class OrderBroadcastOnString
{
    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, string>
     */
    public function broadcastOn(): array
    {
        return ['orders'];
    }
}

class OrderBroadcastOnMixed
{
    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, Channel|string>
     */
    public function broadcastOn(): array
    {
        return ['orders', new PrivateChannel('secret')];
    }
}

class BroadcastableOrder implements HasBroadcastChannel
{
    /**
     * Get the broadcast channel route definition.
     */
    public function broadcastChannelRoute(): string
    {
        return 'orders.{order}';
    }

    /**
     * Get the broadcast channel name.
     */
    public function broadcastChannel(): string
    {
        return 'orders.9';
    }
}

class OrderBroadcastOnModel
{
    /**
     * Get the entity the event should broadcast on.
     */
    public function broadcastOn(): BroadcastableOrder
    {
        return new BroadcastableOrder;
    }
}

class OrderBroadcastOnEveryType
{
    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, Channel|string>
     */
    public function broadcastOn(): array
    {
        return [
            'stats',
            new PrivateChannel('orders.1'),
            new PresenceChannel('room.1'),
            new EncryptedPrivateChannel('secret.1'),
        ];
    }
}

class OrderNamedDuringCancellation
{
    /**
     * Get the channel the event should broadcast on.
     */
    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('orders');
    }

    /**
     * Get the event's broadcast name.
     */
    public function broadcastAs(): string
    {
        throw LivePropTest::$cancellation;
    }
}

class OrderRoutedDuringCancellation
{
    /**
     * Get the channel the event should broadcast on.
     */
    public function broadcastOn(): PrivateChannel
    {
        throw LivePropTest::$cancellation;
    }
}

class OrderRoutedOnCount
{
    /**
     * The number of times the channels were read.
     */
    public int $reads = 0;

    /**
     * Get the channel the event should broadcast on.
     */
    public function broadcastOn(): PrivateChannel
    {
        ++$this->reads;

        return new PrivateChannel('orders');
    }
}

class LivePropTest extends TestCase
{
    /**
     * The cancellation the cancellation fixtures throw.
     */
    public static ?Throwable $cancellation = null;

    public function testCanInvokeWithAValue(): void
    {
        $liveProp = new LiveProp('A live prop value', new OrderUpdated);

        $this->assertSame('A live prop value', $liveProp());
    }

    public function testCanInvokeWithACallback(): void
    {
        $liveProp = new LiveProp(fn () => 'A live prop value', new OrderUpdated);

        $this->assertSame('A live prop value', $liveProp());
    }

    public function testCanResolveBindingsWhenInvoked(): void
    {
        $liveProp = new LiveProp(fn (Request $request) => $request, new OrderUpdated);

        $this->assertInstanceOf(Request::class, $liveProp());
    }

    public function testStringFunctionNamesAreNotInvoked(): void
    {
        $liveProp = new LiveProp('date', new OrderUpdated);

        $this->assertSame('date', $liveProp());
    }

    public function testTheFactoryCreatesALiveProp(): void
    {
        $liveProp = Inertia::live('bar', on: new OrderUpdated);

        $this->assertInstanceOf(LiveProp::class, $liveProp);
        $this->assertTrue($liveProp->isLive());
    }

    public function testPropsAreNotLiveByDefault(): void
    {
        $this->assertFalse((new AlwaysProp('bar'))->isLive());
        $this->assertFalse((new OptionalProp(fn () => 'bar'))->isLive());
        $this->assertFalse((new OnceProp(fn () => 'bar'))->isLive());
        $this->assertFalse((new DeferProp(fn () => 'bar'))->isLive());
        $this->assertFalse(is_a(MergeProp::class, SupportsLiveUpdates::class, true));
        $this->assertFalse(is_a(ScrollProp::class, SupportsLiveUpdates::class, true));
    }

    public function testSupportedPropTypesCanBeMarkedAsLive(): void
    {
        $props = [
            new AlwaysProp('bar'),
            new OptionalProp(fn () => 'bar'),
            new OnceProp(fn () => 'bar'),
            new DeferProp(fn () => 'bar'),
        ];

        foreach ($props as $prop) {
            $result = $prop->live(on: new OrderUpdated);

            $this->assertSame($prop, $result);
            $this->assertTrue($prop->isLive());
            $this->assertSame([
                [
                    'channel' => ['name' => 'orders.1', 'type' => 'private'],
                    'events' => [OrderUpdated::class],
                ],
            ], $prop->liveListeners());
        }
    }

    public function testAMergePropCannotBeMadeLive(): void
    {
        $prop = (new DeferProp(fn () => 'bar'))->merge();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Live updates are not supported on merge properties.');

        $prop->live(on: new OrderUpdated);
    }

    public function testALivePropCannotBeMadeMergeable(): void
    {
        // Guarded from both sides, since the order the two are called in is
        // the author's to choose
        $prop = (new DeferProp(fn () => 'bar'))->live(on: new OrderUpdated);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Live updates are not supported on merge properties.');

        $prop->merge();
    }

    public function testADeepMergePropCannotBeMadeLive(): void
    {
        $prop = (new DeferProp(fn () => 'bar'))->deepMerge();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Live updates are not supported on merge properties.');

        $prop->live(on: new OrderUpdated);
    }

    public function testALivePropMayStillConfigureHowItWouldMerge(): void
    {
        // Only [merge] declares the intent, so appending at a path leaves a
        // live prop alone until it is actually marked for merging
        $prop = (new DeferProp(fn () => 'bar'))->live(on: new OrderUpdated)->append('items');

        $this->assertTrue($prop->isLive());
        $this->assertFalse($prop->shouldMerge());
    }

    public function testTheEventClassNameIsUsedAsTheEventName(): void
    {
        $liveProp = new LiveProp('bar', new OrderUpdated);

        $this->assertSame([OrderUpdated::class], $liveProp->liveListeners()[0]['events']);
    }

    public function testTheBroadcastAsNameIsUsedAsTheEventName(): void
    {
        $liveProp = new LiveProp('bar', new OrderCreated);

        $this->assertSame(['order.created'], $liveProp->liveListeners()[0]['events']);
    }

    public function testCanListenForMultipleEvents(): void
    {
        $liveProp = new LiveProp('bar', [new OrderUpdated, new OrderShipped]);

        $this->assertSame([
            [
                'channel' => ['name' => 'orders.1', 'type' => 'private'],
                'events' => [OrderUpdated::class],
            ],
            [
                'channel' => ['name' => 'stats', 'type' => 'public'],
                'events' => [OrderShipped::class],
            ],
            [
                'channel' => ['name' => 'room.1', 'type' => 'presence'],
                'events' => [OrderShipped::class],
            ],
        ], $liveProp->liveListeners());
    }

    public function testEventsOnDifferentChannelsKeepTheirOwnListenerPairs(): void
    {
        $liveProp = new LiveProp('bar', [new OrderUpdated, new UserUpdated]);

        $this->assertSame([
            [
                'channel' => ['name' => 'orders.1', 'type' => 'private'],
                'events' => [OrderUpdated::class],
            ],
            [
                'channel' => ['name' => 'users.7', 'type' => 'private'],
                'events' => [UserUpdated::class],
            ],
        ], $liveProp->liveListeners());
    }

    public function testEventsOnTheSameChannelCollapseIntoOneListener(): void
    {
        $liveProp = new LiveProp('bar', [new OrderUpdated, new OrderPaid]);

        $this->assertSame([
            [
                'channel' => ['name' => 'orders.1', 'type' => 'private'],
                'events' => [OrderUpdated::class, OrderPaid::class],
            ],
        ], $liveProp->liveListeners());
    }

    public function testOneEventBroadcastingOnSeveralChannelsGetsOneListenerPerChannel(): void
    {
        $liveProp = new LiveProp('bar', new OrderShipped);

        $this->assertSame([
            [
                'channel' => ['name' => 'stats', 'type' => 'public'],
                'events' => [OrderShipped::class],
            ],
            [
                'channel' => ['name' => 'room.1', 'type' => 'presence'],
                'events' => [OrderShipped::class],
            ],
        ], $liveProp->liveListeners());
    }

    public function testExplicitChannelsPairEveryEventWithEveryChannel(): void
    {
        $liveProp = new LiveProp('bar', [new OrderUpdated, new UserUpdated], ['orders.1', new Channel('stats')]);

        $this->assertSame([
            [
                'channel' => ['name' => 'orders.1', 'type' => 'public'],
                'events' => [OrderUpdated::class, UserUpdated::class],
            ],
            [
                'channel' => ['name' => 'stats', 'type' => 'public'],
                'events' => [OrderUpdated::class, UserUpdated::class],
            ],
        ], $liveProp->liveListeners());
    }

    public function testDuplicateEventsAndChannelsAreRemoved(): void
    {
        $liveProp = new LiveProp('bar', [new OrderUpdated(1), new OrderUpdated(1)]);

        $this->assertSame([
            [
                'channel' => ['name' => 'orders.1', 'type' => 'private'],
                'events' => [OrderUpdated::class],
            ],
        ], $liveProp->liveListeners());
    }

    public function testTheChannelTypeIsResolvedFromTheChannelClass(): void
    {
        $this->assertSame(
            [['channel' => ['name' => 'stats', 'type' => 'public'], 'events' => [OrderUpdated::class]]],
            (new LiveProp('bar', new OrderUpdated, new Channel('stats')))->liveListeners()
        );

        $this->assertSame(
            [['channel' => ['name' => 'orders.1', 'type' => 'private'], 'events' => [OrderUpdated::class]]],
            (new LiveProp('bar', new OrderUpdated, new PrivateChannel('orders.1')))->liveListeners()
        );

        $this->assertSame(
            [['channel' => ['name' => 'room.1', 'type' => 'presence'], 'events' => [OrderUpdated::class]]],
            (new LiveProp('bar', new OrderUpdated, new PresenceChannel('room.1')))->liveListeners()
        );

        $this->assertSame(
            [['channel' => ['name' => 'orders.1', 'type' => 'encrypted-private'], 'events' => [OrderUpdated::class]]],
            (new LiveProp('bar', new OrderUpdated, new EncryptedPrivateChannel('orders.1')))->liveListeners()
        );
    }

    public function testTheChannelPrefixIsNeverBroadcast(): void
    {
        $liveProp = new LiveProp('bar', new OrderSecured);

        $this->assertSame(['name' => 'orders.1', 'type' => 'encrypted-private'], $liveProp->liveListeners()[0]['channel']);
    }

    public function testAPublicChannelKeepsANameThatLooksPrefixed(): void
    {
        $liveProp = new LiveProp('bar', new OrderUpdated, new Channel('private-orders.1'));

        $this->assertSame([['channel' => ['name' => 'private-orders.1', 'type' => 'public'], 'events' => [OrderUpdated::class]]], $liveProp->liveListeners());
    }

    public function testABareStringChannelIsPublicWhereverItIsGiven(): void
    {
        // Laravel casts a channel to a string without adding a prefix, so a bare
        // string means the same thing here as it does in [broadcastOn]
        $given = new LiveProp('bar', new OrderUpdated, 'orders');
        $broadcast = new LiveProp('bar', new OrderBroadcastOnString);

        $this->assertSame(['name' => 'orders', 'type' => 'public'], $given->liveListeners()[0]['channel']);
        $this->assertSame(['name' => 'orders', 'type' => 'public'], $broadcast->liveListeners()[0]['channel']);
    }

    public function testCanSubscribeToMultipleChannels(): void
    {
        $liveProp = new LiveProp('bar', new OrderUpdated, ['orders.1', new Channel('stats')]);

        $this->assertSame([
            ['channel' => ['name' => 'orders.1', 'type' => 'public'], 'events' => [OrderUpdated::class]],
            ['channel' => ['name' => 'stats', 'type' => 'public'], 'events' => [OrderUpdated::class]],
        ], $liveProp->liveListeners());
    }

    public function testTheGivenChannelWinsOverTheChannelsTheEventBroadcastsOn(): void
    {
        $liveProp = new LiveProp('bar', new OrderUpdated, 'other-orders.1');

        $this->assertSame([['channel' => ['name' => 'other-orders.1', 'type' => 'public'], 'events' => [OrderUpdated::class]]], $liveProp->liveListeners());
    }

    public function testCanListenForAnEventClassName(): void
    {
        $liveProp = new LiveProp('bar', OrderRestored::class, 'orders.1');

        $this->assertSame([
            [
                'channel' => ['name' => 'orders.1', 'type' => 'public'],
                'events' => [OrderRestored::class],
            ],
        ], $liveProp->liveListeners());
    }

    public function testAnEventClassNameResolvesItsOwnBroadcastName(): void
    {
        $liveProp = new LiveProp('bar', OrderArchived::class, 'orders.1');

        $this->assertSame(['order.archived'], $liveProp->liveListeners()[0]['events']);
    }

    public function testAnEventClassNameWhoseBroadcastNameComesFromThePayloadThrows(): void
    {
        // The payload is what a class name cannot supply, so the name would
        // otherwise resolve against an uninitialised property
        $liveProp = new LiveProp('bar', OrderRenamed::class, 'orders.1');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Unable to resolve the broadcast name for the [' . OrderRenamed::class . '] event because it builds it from its payload.');

        $liveProp->liveListeners();
    }

    public function testAnEnumBroadcastNameResolvesToItsValue(): void
    {
        $this->assertSame(['order.refunded'], (new LiveProp('bar', new OrderRefunded))->liveListeners()[0]['events']);
        $this->assertSame(['order.refunded'], (new LiveProp('bar', OrderRefunded::class))->liveListeners()[0]['events']);
    }

    public function testAnIntegerBackedEnumBroadcastNameResolvesToItsValueAsAString(): void
    {
        $this->assertSame(['42'], (new LiveProp('bar', new OrderEscalated))->liveListeners()[0]['events']);
        $this->assertSame(['42'], (new LiveProp('bar', OrderEscalated::class))->liveListeners()[0]['events']);
    }

    public function testAnEventClassNameThatDoesNotExistThrows(): void
    {
        $liveProp = new LiveProp('bar', 'App\Events\OrderUpdated', 'orders.1');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The [App\Events\OrderUpdated] event class does not exist.');

        $liveProp->liveListeners();
    }

    public function testAnEventNameWithoutANamespaceIsLeftUntouched(): void
    {
        $liveProp = new LiveProp('bar', ['order.updated', 'OrderUpdated'], 'orders.1');

        $this->assertSame(['order.updated', 'OrderUpdated'], $liveProp->liveListeners()[0]['events']);
    }

    public function testAnEventClassNameResolvesItsOwnChannels(): void
    {
        $liveProp = new LiveProp('bar', OrderRestored::class);

        $this->assertSame([
            [
                'channel' => ['name' => 'orders', 'type' => 'private'],
                'events' => [OrderRestored::class],
            ],
        ], $liveProp->liveListeners());
    }

    public function testAnEventClassNameThatBuildsItsChannelsFromThePayloadThrows(): void
    {
        // The payload is what a class name cannot supply, so [OrderUpdated] would
        // otherwise resolve to `orders.` and listen on a channel nobody broadcasts on
        $liveProp = new LiveProp('bar', OrderUpdated::class);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Unable to resolve the channels for the [' . OrderUpdated::class . '] event because it builds them from its payload.');

        $liveProp->liveListeners();
    }

    public function testAnEventClassNameWithAnUntypedPayloadThrows(): void
    {
        // A null property read raises a warning rather than an Error, which
        // Hypervel turns into an ErrorException
        $liveProp = new LiveProp('bar', OrderNoted::class);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Unable to resolve the channels for the [' . OrderNoted::class . '] event because it builds them from its payload.');

        $liveProp->liveListeners();
    }

    public function testCancellationWhileResolvingAnEventClassBroadcastNameIsRethrown(): void
    {
        static::$cancellation = new CanceledException('canceled');

        try {
            (new LiveProp('bar', OrderNamedDuringCancellation::class, 'orders.1'))->liveListeners();

            $this->fail('The cancellation was not rethrown.');
        } catch (CanceledException $exception) {
            $this->assertSame(static::$cancellation, $exception);
        }
    }

    public function testCancellationWhileResolvingEventClassChannelsIsRethrown(): void
    {
        static::$cancellation = new CanceledException('canceled');

        try {
            (new LiveProp('bar', OrderRoutedDuringCancellation::class))->liveListeners();

            $this->fail('The cancellation was not rethrown.');
        } catch (CanceledException $exception) {
            $this->assertSame(static::$cancellation, $exception);
        }
    }

    public function testChannelsKeyedByConnectionAreReadForTheDefaultConnection(): void
    {
        config(['broadcasting.default' => 'reverb']);

        $this->assertSame([
            [
                'channel' => ['name' => 'orders.1', 'type' => 'private'],
                'events' => [OrderBroadcastPerConnection::class],
            ],
        ], (new LiveProp('bar', new OrderBroadcastPerConnection))->liveListeners());
    }

    public function testChannelsKeyedByConnectionFollowTheConnectionTheEventIsBroadcastVia(): void
    {
        config(['broadcasting.default' => 'reverb']);

        // Without a named connection, the broadcast job sends the '' channels on the default connection
        $this->assertSame([
            [
                'channel' => ['name' => 'orders.1', 'type' => 'private'],
                'events' => [OrderBroadcastOnUnnamedOrDefaultConnection::class],
            ],
        ], (new LiveProp('bar', new OrderBroadcastOnUnnamedOrDefaultConnection))->liveListeners());

        $this->assertSame([
            [
                'channel' => ['name' => 'reverb.orders.1', 'type' => 'private'],
                'events' => [OrderBroadcastOnUnnamedOrDefaultConnection::class],
            ],
        ], (new LiveProp('bar', (new OrderBroadcastOnUnnamedOrDefaultConnection)->broadcastVia('reverb')))->liveListeners());

        // Both groups go out on the default connection when the event is broadcast via both
        $this->assertSame([
            [
                'channel' => ['name' => 'orders.1', 'type' => 'private'],
                'events' => [OrderBroadcastOnUnnamedOrDefaultConnection::class],
            ],
            [
                'channel' => ['name' => 'reverb.orders.1', 'type' => 'private'],
                'events' => [OrderBroadcastOnUnnamedOrDefaultConnection::class],
            ],
        ], (new LiveProp('bar', (new OrderBroadcastOnUnnamedOrDefaultConnection)->broadcastVia([null, 'reverb'])))->liveListeners());
    }

    public function testChannelsKeyedByConnectionForAnEventNotBroadcastOnTheDefaultConnectionThrow(): void
    {
        config(['broadcasting.default' => 'reverb']);

        $liveProp = new LiveProp('bar', (new OrderBroadcastOnUnnamedOrDefaultConnection)->broadcastVia('pusher'));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The [' . OrderBroadcastOnUnnamedOrDefaultConnection::class . '] event keys its channels by connection, but has none for the default [reverb] connection.');

        $liveProp->liveListeners();
    }

    public function testChannelsKeyedByConnectionWithoutTheDefaultConnectionThrow(): void
    {
        config(['broadcasting.default' => 'ably']);

        $liveProp = new LiveProp('bar', new OrderBroadcastPerConnection);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The [' . OrderBroadcastPerConnection::class . '] event keys its channels by connection, but has none for the default [ably] connection.');

        $liveProp->liveListeners();
    }

    public function testABroadcastNameWithoutAChannelThrows(): void
    {
        $liveProp = new LiveProp('bar', 'order.updated');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Unable to resolve the channels for the [order.updated] event because it was given as a string.');

        $liveProp->liveListeners();
    }

    public function testAnEventWithoutChannelsThrows(): void
    {
        $liveProp = new LiveProp('bar', new OrderWithoutChannels);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The [' . OrderWithoutChannels::class . '] event does not define a [broadcastOn] method.');

        $liveProp->liveListeners();
    }

    public function testAnEventThatBroadcastsOnNoChannelsThrows(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The [' . OrderWithEmptyChannels::class . '] event does not broadcast on any channels.');

        (new LiveProp('bar', new OrderWithEmptyChannels))->liveListeners();
    }

    public function testALivePropWithoutEventsThrows(): void
    {
        $liveProp = new LiveProp('bar');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('A live property must listen for at least one event.');

        $liveProp->liveListeners();
    }

    public function testTheThrottleIsNullByDefault(): void
    {
        $liveProp = new LiveProp('bar', new OrderUpdated);

        $this->assertNull($liveProp->liveThrottle());
    }

    public function testCanSetTheThrottle(): void
    {
        $liveProp = Inertia::live('bar', on: new OrderUpdated, throttle: 5000);

        $this->assertSame(5000, $liveProp->liveThrottle());
    }

    public function testUnrelatedMergeAndLivePropsCanRenderTogether(): void
    {
        $page = $this->renderPage([
            'orders' => Inertia::merge(['bar']),
            'metrics' => Inertia::live(fn () => ['count' => 1], on: 'order.created', channel: 'metrics'),
        ]);

        $this->assertSame(['bar'], $page['props']['orders']);
        $this->assertSame(['count' => 1], $page['props']['metrics']);
        $this->assertSame(['orders'], $page['mergeProps']);
        $this->assertArrayHasKey('metrics', $page['liveProps']);
    }

    public function testLiveCanBeCalledMoreThanOnce(): void
    {
        $prop = (new LiveProp('bar', new OrderUpdated))->live(on: new OrderShipped);

        $this->assertSame([
            [
                'channel' => ['name' => 'orders.1', 'type' => 'private'],
                'events' => [OrderUpdated::class],
            ],
            [
                'channel' => ['name' => 'stats', 'type' => 'public'],
                'events' => [OrderShipped::class],
            ],
            [
                'channel' => ['name' => 'room.1', 'type' => 'presence'],
                'events' => [OrderShipped::class],
            ],
        ], $prop->liveListeners());
    }

    public function testAnExplicitChannelDoesNotLeakIntoALaterDeclaration(): void
    {
        $prop = (new LiveProp('bar', new OrderUpdated, 'orders.1'))->live(on: new OrderShipped);

        $this->assertSame([
            [
                'channel' => ['name' => 'orders.1', 'type' => 'public'],
                'events' => [OrderUpdated::class],
            ],
            [
                'channel' => ['name' => 'stats', 'type' => 'public'],
                'events' => [OrderShipped::class],
            ],
            [
                'channel' => ['name' => 'room.1', 'type' => 'presence'],
                'events' => [OrderShipped::class],
            ],
        ], $prop->liveListeners());
    }

    public function testALaterExplicitChannelDoesNotLeakIntoAnEarlierDeclaration(): void
    {
        $prop = (new LiveProp('bar', new OrderUpdated))->live(on: new OrderShipped, channel: 'stats');

        $this->assertSame([
            [
                'channel' => ['name' => 'orders.1', 'type' => 'private'],
                'events' => [OrderUpdated::class],
            ],
            [
                'channel' => ['name' => 'stats', 'type' => 'public'],
                'events' => [OrderShipped::class],
            ],
        ], $prop->liveListeners());
    }

    public function testAChannelDeclaredWithoutAnEventContributesNothing(): void
    {
        $prop = (new LiveProp('bar', new OrderUpdated))->live(channel: 'ignored');

        $this->assertSame([
            [
                'channel' => ['name' => 'orders.1', 'type' => 'private'],
                'events' => [OrderUpdated::class],
            ],
        ], $prop->liveListeners());
    }

    public function testBroadcastOnCanMixBareStringsAndChannelObjects(): void
    {
        $liveProp = new LiveProp('bar', new OrderBroadcastOnMixed);

        $this->assertSame([
            [
                'channel' => ['name' => 'orders', 'type' => 'public'],
                'events' => [OrderBroadcastOnMixed::class],
            ],
            [
                'channel' => ['name' => 'secret', 'type' => 'private'],
                'events' => [OrderBroadcastOnMixed::class],
            ],
        ], $liveProp->liveListeners());
    }

    public function testBroadcastOnCanReturnABroadcastableModel(): void
    {
        $liveProp = new LiveProp('bar', new OrderBroadcastOnModel);

        $this->assertSame([
            [
                'channel' => ['name' => 'orders.9', 'type' => 'public'],
                'events' => [OrderBroadcastOnModel::class],
            ],
        ], $liveProp->liveListeners());
    }

    #[DataProvider('formattedChannelSources')]
    public function testFormattedChannelsAreAdvertisedWithTheirTypes(LiveProp $liveProp, string $event): void
    {
        $received = [];

        // Insert a prefix after the channel type, the way a tenant prefix keeps
        // the type prefix every broadcaster reads first
        Broadcaster::formatChannelsUsing(function (array $channels) use (&$received): array {
            $received[] = $channels;

            return array_map(function (Channel|string $channel): string {
                $name = (string) $channel;

                foreach (['private-encrypted-', 'private-', 'presence-'] as $prefix) {
                    if (str_starts_with($name, $prefix)) {
                        return $prefix . 'tenant.' . substr($name, strlen($prefix));
                    }
                }

                return 'tenant.' . $name;
            }, $channels);
        });

        $this->assertSame([
            ['channel' => ['name' => 'tenant.stats', 'type' => 'public'], 'events' => [$event]],
            ['channel' => ['name' => 'tenant.orders.1', 'type' => 'private'], 'events' => [$event]],
            ['channel' => ['name' => 'tenant.room.1', 'type' => 'presence'], 'events' => [$event]],
            ['channel' => ['name' => 'tenant.secret.1', 'type' => 'encrypted-private'], 'events' => [$event]],
        ], $liveProp->liveListeners());

        // The formatter receives the unconverted channels together, as it does when broadcasting
        $this->assertCount(1, $received);
        $this->assertCount(4, $received[0]);
        $this->assertInstanceOf(PrivateChannel::class, $received[0][1]);
    }

    /**
     * Get the live props whose channels are formatted.
     *
     * @return array<string, array{LiveProp, string}>
     */
    public static function formattedChannelSources(): array
    {
        return [
            'channels the event broadcasts on' => [
                new LiveProp('bar', new OrderBroadcastOnEveryType),
                OrderBroadcastOnEveryType::class,
            ],
            'the given channels' => [
                new LiveProp('bar', 'order.updated', [
                    'stats',
                    new PrivateChannel('orders.1'),
                    new PresenceChannel('room.1'),
                    new EncryptedPrivateChannel('secret.1'),
                ]),
                'order.updated',
            ],
        ];
    }

    public function testAFormattedBroadcastableModelIsAdvertisedByItsChannelName(): void
    {
        // Typed to fail if the model reaches the formatter instead of its channel name
        Broadcaster::formatChannelsUsing(fn (array $channels): array => array_map(
            fn (Channel|string $channel): string => 'tenant.' . $channel,
            $channels,
        ));

        $liveProp = new LiveProp('bar', new OrderBroadcastOnModel);

        $this->assertSame([
            [
                'channel' => ['name' => 'tenant.orders.9', 'type' => 'public'],
                'events' => [OrderBroadcastOnModel::class],
            ],
        ], $liveProp->liveListeners());
    }

    public function testARescuedLivePropDoesNotReEmitItsListeners(): void
    {
        $page = $this->renderPage([
            'orders' => Inertia::defer(fn () => throw new RuntimeException('boom'), rescue: true)->live(on: new OrderCreated),
        ], [
            'X-Inertia' => 'true',
            'X-Inertia-Partial-Component' => 'User/Edit',
            'X-Inertia-Partial-Data' => 'orders',
        ]);

        // The resolver threw, so there is no fresh channel to report. The client
        // keeps the listeners it already received, because an omitted prop means
        // preserve rather than unsubscribe.
        $this->assertContains('orders', $page['rescuedProps']);
        $this->assertArrayNotHasKey('orders', $page['liveProps'] ?? []);
    }

    public function testARescuedLivePropReturnedFromAClosureIsLeftOutWithItsListeners(): void
    {
        $page = $this->renderPage([
            'orders' => fn () => Inertia::defer(fn () => throw new RuntimeException('boom'), rescue: true)->live(on: new OrderCreated),
        ], [
            'X-Inertia' => 'true',
            'X-Inertia-Partial-Component' => 'User/Edit',
            'X-Inertia-Partial-Data' => 'orders',
        ]);

        $this->assertSame(['orders'], $page['rescuedProps']);
        $this->assertArrayNotHasKey('orders', $page['props']);
        $this->assertArrayNotHasKey('orders', $page['liveProps'] ?? []);
    }

    public function testALivePropThatIsRescuedStillAnnouncesItsListenersOnTheInitialLoad(): void
    {
        $page = $this->renderPage([
            'orders' => Inertia::defer(fn () => throw new RuntimeException('boom'), rescue: true)->live(on: new OrderCreated),
        ]);

        // The deferred prop is excluded from the initial load before anything is
        // resolved, so the client learns what to listen for up front
        $this->assertArrayHasKey('orders', $page['liveProps']);
    }

    public function testASentLivePropReadsItsChannelsOncePerResponse(): void
    {
        $initial = new OrderRoutedOnCount;
        $page = $this->renderPage([
            'order' => Inertia::live(fn () => 'bar', on: $initial),
        ]);

        $partial = new OrderRoutedOnCount;
        $this->renderPage([
            'order' => Inertia::live(fn () => 'bar', on: $partial),
        ], [
            'X-Inertia' => 'true',
            'X-Inertia-Partial-Component' => 'User/Edit',
            'X-Inertia-Partial-Data' => 'order',
        ]);

        $this->assertArrayHasKey('order', $page['liveProps']);
        $this->assertSame(1, $initial->reads);
        $this->assertSame(1, $partial->reads);
    }

    public function testTheLiveManifestIsSerializedIntoThePage(): void
    {
        $page = $this->renderPage([
            'order' => Inertia::live(fn () => 'bar', on: new OrderUpdated),
        ]);

        $this->assertSame('bar', $page['props']['order']);

        $this->assertSame([
            'order' => [
                'listeners' => [
                    [
                        'channel' => ['name' => 'orders.1', 'type' => 'private'],
                        'events' => [OrderUpdated::class],
                    ],
                ],
            ],
        ], $page['liveProps']);
        $this->assertArrayNotHasKey('throttle', $page['liveProps']['order']);
    }

    public function testTheLiveManifestSerializesASetThrottle(): void
    {
        $page = $this->renderPage([
            'order' => Inertia::live(fn () => 'bar', on: new OrderUpdated, throttle: 500),
        ]);

        $this->assertSame([
            'listeners' => [
                [
                    'channel' => ['name' => 'orders.1', 'type' => 'private'],
                    'events' => [OrderUpdated::class],
                ],
            ],
            'throttle' => 500,
        ], $page['liveProps']['order']);
    }

    public function testTheLivePropsKeyIsOmittedWhenNoPropsAreLive(): void
    {
        $page = $this->renderPage(['order' => 'bar']);

        $this->assertArrayNotHasKey('liveProps', $page);
    }

    public function testALivePropReturnedFromAClosureIsUnwrapped(): void
    {
        $page = $this->renderPage([
            'order' => fn () => Inertia::live('bar', on: new OrderUpdated),
        ]);

        $this->assertSame('bar', $page['props']['order']);
        $this->assertArrayHasKey('order', $page['liveProps']);
    }

    public function testDeferredPropsAreLiveOnTheInitialLoad(): void
    {
        $page = $this->renderPage([
            'orders' => Inertia::defer(fn () => ['bar'])->live(on: new OrderCreated),
        ]);

        $this->assertArrayNotHasKey('orders', $page['props']);
        $this->assertSame(['default' => ['orders']], $page['deferredProps']);

        $this->assertSame([
            'orders' => [
                'listeners' => [
                    [
                        'channel' => ['name' => 'orders', 'type' => 'public'],
                        'events' => ['order.created'],
                    ],
                ],
            ],
        ], $page['liveProps']);
    }

    public function testOptionalPropsAreLiveOnTheInitialLoad(): void
    {
        $page = $this->renderPage([
            'order' => Inertia::optional(fn () => 'bar')->live(on: new OrderUpdated),
        ]);

        $this->assertArrayNotHasKey('order', $page['props']);
        $this->assertArrayHasKey('order', $page['liveProps']);
    }

    public function testAlwaysPropsAreLiveOnTheInitialLoad(): void
    {
        $page = $this->renderPage([
            'orders' => Inertia::always(fn () => ['bar'])->live(on: new OrderCreated),
        ]);

        $this->assertSame(['bar'], $page['props']['orders']);
        $this->assertArrayHasKey('orders', $page['liveProps']);
    }

    public function testDeferredLivePropsCanStillAppendWithoutMerging(): void
    {
        $page = $this->renderPage([
            'orders' => Inertia::defer(fn () => ['items' => ['bar']])->live(on: new OrderCreated)->append('items'),
        ]);

        $this->assertArrayNotHasKey('orders', $page['props']);
        $this->assertArrayHasKey('orders', $page['liveProps']);
    }

    public function testOncePropsTheClientAlreadyLoadedAreStillLive(): void
    {
        $page = $this->renderPage(
            ['order' => Inertia::once(fn () => 'bar')->live(on: new OrderUpdated)],
            ['X-Inertia' => 'true', 'X-Inertia-Except-Once-Props' => 'order'],
        );

        $this->assertArrayNotHasKey('order', $page['props']);
        $this->assertArrayHasKey('order', $page['liveProps']);
    }

    public function testTheLiveManifestIsSerializedOnPartialRequests(): void
    {
        $page = $this->renderPage([
            'order' => Inertia::live(fn () => 'bar', on: new OrderUpdated),
            'metrics' => Inertia::live(fn () => 'baz', on: new OrderShipped),
        ], [
            'X-Inertia' => 'true',
            'X-Inertia-Partial-Component' => 'User/Edit',
            'X-Inertia-Partial-Data' => 'order',
        ]);

        $this->assertSame(['order' => 'bar'], $page['props']);
        $this->assertSame(['order'], array_keys($page['liveProps']));
    }

    public function testALivePropResolvedForANestedPartialRequestSendsItsListeners(): void
    {
        $page = $this->renderPage([
            'order' => Inertia::live(fn () => ['id' => 2, 'total' => 200], on: new OrderUpdated(2)),
            'metrics' => Inertia::live(fn () => 'baz', on: new OrderShipped),
            'status' => Inertia::live(fn () => 'ready', on: new OrderCreated),
        ], [
            'X-Inertia' => 'true',
            'X-Inertia-Partial-Component' => 'User/Edit',
            'X-Inertia-Partial-Data' => 'order.total,status',
            'X-Inertia-Partial-Except' => 'status',
        ]);

        // The live prop resolves whole for its nested path, so the listeners for
        // its new channel are sent with it
        $this->assertSame(['order' => ['id' => 2, 'total' => 200]], $page['props']);
        $this->assertSame(['order'], array_keys($page['liveProps']));
        $this->assertSame([['channel' => ['name' => 'orders.2', 'type' => 'private'], 'events' => [OrderUpdated::class]]], $page['liveProps']['order']['listeners']);
    }

    public function testLiveAlwaysPropsKeepMetadataOnUnrelatedPartialRequests(): void
    {
        $page = $this->renderPage([
            'order' => Inertia::always(fn () => 'bar')->live(on: new OrderUpdated(2)),
            'metrics' => Inertia::live(fn () => 'baz', on: new OrderShipped),
            'status' => Inertia::live(fn () => 'ready', on: new OrderCreated),
        ], [
            'X-Inertia' => 'true',
            'X-Inertia-Partial-Component' => 'User/Edit',
            'X-Inertia-Partial-Data' => 'metrics',
        ]);

        $this->assertSame(['order' => 'bar', 'metrics' => 'baz'], $page['props']);
        $this->assertArrayHasKey('order', $page['liveProps']);
        $this->assertSame([['channel' => ['name' => 'orders.2', 'type' => 'private'], 'events' => [OrderUpdated::class]]], $page['liveProps']['order']['listeners']);
        $this->assertArrayHasKey('metrics', $page['liveProps']);
        $this->assertArrayNotHasKey('status', $page['liveProps']);
    }

    public function testLivePropsUnderAnAlwaysParentKeepMetadataOnUnrelatedPartialRequests(): void
    {
        $page = $this->renderPage([
            'order' => Inertia::always(fn () => ['total' => Inertia::live(fn () => 100, on: new OrderUpdated(2))]),
            'metrics' => Inertia::live(fn () => 'baz', on: new OrderShipped),
        ], [
            'X-Inertia' => 'true',
            'X-Inertia-Partial-Component' => 'User/Edit',
            'X-Inertia-Partial-Data' => 'metrics',
        ]);

        // The value is sent because its parent resolved outside the filter, so
        // its listeners have to be sent with it
        $this->assertSame(['order' => ['total' => 100], 'metrics' => 'baz'], $page['props']);
        $this->assertArrayHasKey('order.total', $page['liveProps']);
        $this->assertSame([['channel' => ['name' => 'orders.2', 'type' => 'private'], 'events' => [OrderUpdated::class]]], $page['liveProps']['order.total']['listeners']);
    }

    public function testLivePropsUnderAnAlwaysParentKeepMetadataWhenExcepted(): void
    {
        $page = $this->renderPage([
            'order' => Inertia::always(fn () => ['total' => Inertia::live(fn () => 100, on: new OrderUpdated(3))]),
        ], [
            'X-Inertia' => 'true',
            'X-Inertia-Partial-Component' => 'User/Edit',
            'X-Inertia-Partial-Except' => 'order',
        ]);

        // An always prop outranks [except], so its children are sent and their
        // listeners have to be sent too
        $this->assertSame(['order' => ['total' => 100]], $page['props']);
        $this->assertArrayHasKey('order.total', $page['liveProps']);
    }

    public function testNestedLivePropsAreKeyedByTheirDotPath(): void
    {
        $page = $this->renderPage([
            'order' => ['total' => Inertia::live(fn () => 100, on: new OrderUpdated)],
        ]);

        $this->assertSame(['order' => ['total' => 100]], $page['props']);
        $this->assertSame(['order.total'], array_keys($page['liveProps']));
    }

    /**
     * Render a page and return its page object.
     *
     * @param array<string, mixed> $props
     * @param array<string, string> $headers
     * @return array<string, mixed>
     */
    protected function renderPage(array $props, array $headers = ['X-Inertia' => 'true']): array
    {
        $request = Request::create('/user/123', 'GET');
        $request->headers->add($headers);

        $response = new Response('User/Edit', [], $props, 'app', '123');

        /** @var JsonResponse $response */
        $response = $response->toResponse($request);

        return $response->getData(true);
    }
}
