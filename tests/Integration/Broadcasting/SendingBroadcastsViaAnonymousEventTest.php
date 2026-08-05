<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Broadcasting;

use Hypervel\Broadcasting\AnonymousEvent;
use Hypervel\Broadcasting\PresenceChannel;
use Hypervel\Broadcasting\PrivateChannel;
use Hypervel\Context\RequestContext;
use Hypervel\Http\Request;
use Hypervel\Support\Facades\Broadcast;
use Hypervel\Support\Facades\Event;
use Hypervel\Testbench\TestCase;
use Mockery as m;
use ReflectionClass;

class SendingBroadcastsViaAnonymousEventTest extends TestCase
{
    public function testBroadcastIsSent(): void
    {
        Event::fake();

        Broadcast::on('test-channel')
            ->with(['some' => 'data'])
            ->as('test-event')
            ->send();

        Event::assertDispatched(AnonymousEvent::class, function ($event) {
            return (new ReflectionClass($event))->getProperty('connection')->getValue($event) === null
                && $event->broadcastOn() === ['test-channel']
                && $event->broadcastAs() === 'test-event'
                && $event->broadcastWith() === ['some' => 'data'];
        });
    }

    public function testBroadcastIsSentNow(): void
    {
        Event::fake();

        Broadcast::on('test-channel')
            ->with(['some' => 'data'])
            ->as('test-event')
            ->sendNow();

        Event::assertDispatched(AnonymousEvent::class, function ($event) {
            return (new ReflectionClass($event))->getProperty('connection')->getValue($event) === null
                && $event->shouldBroadcastNow();
        });
    }

    public function testDefaultNameIsSet(): void
    {
        Event::fake();

        Broadcast::on('test-channel')
            ->with(['some' => 'data'])
            ->send();

        Event::assertDispatched(AnonymousEvent::class, function ($event) {
            return $event->broadcastAs() === 'AnonymousEvent';
        });
    }

    public function testZeroNameIsPreserved(): void
    {
        Event::fake();

        Broadcast::on('test-channel')->as('0')->send();

        Event::assertDispatched(AnonymousEvent::class, function ($event) {
            return $event->broadcastAs() === '0';
        });
    }

    public function testEmptyNameUsesDefaultName(): void
    {
        Event::fake();

        Broadcast::on('test-channel')->as('')->send();

        Event::assertDispatched(AnonymousEvent::class, function ($event) {
            return $event->broadcastAs() === 'AnonymousEvent';
        });
    }

    public function testDefaultPayloadIsSet(): void
    {
        Event::fake();

        Broadcast::on('test-channel')->send();

        Event::assertDispatched(AnonymousEvent::class, function ($event) {
            return $event->broadcastWith() === [];
        });
    }

    public function testSendToMultipleChannels(): void
    {
        Event::fake();

        Broadcast::on([
            'test-channel',
            new PrivateChannel('test-channel'),
            'presence-test-channel',
        ])->send();

        Event::assertDispatched(AnonymousEvent::class, function ($event) {
            [$one, $two, $three] = $event->broadcastOn();

            return $one === 'test-channel'
                && $two instanceof PrivateChannel
                && $two->name === 'private-test-channel'
                && $three === 'presence-test-channel';
        });
    }

    public function testSendViaANonDefaultConnection(): void
    {
        Event::fake();

        Broadcast::on('test-channel')
            ->via('pusher')
            ->send();

        Event::assertDispatched(AnonymousEvent::class, function ($event) {
            return (new ReflectionClass($event))->getProperty('connection')->getValue($event) === 'pusher';
        });
    }

    public function testSendToOthersOnly(): void
    {
        Event::fake();

        $request = m::mock(Request::class);
        $request->shouldReceive('header')->with('X-Socket-ID')->andReturn('12345');
        $request->shouldReceive('setUserResolver')->andReturnSelf();
        RequestContext::set($request);

        Broadcast::on('test-channel')->send();

        Event::assertDispatched(AnonymousEvent::class, function ($event) {
            return $event->socket === null;
        });

        Broadcast::on('test-channel')
            ->toOthers()
            ->send();

        Event::assertDispatched(AnonymousEvent::class, function ($event) {
            return $event->socket === '12345';
        });
    }

    public function testSendToPrivateChannel(): void
    {
        Event::fake();

        Broadcast::private('test-channel')->send();

        Event::assertDispatched(AnonymousEvent::class, function ($event) {
            $channel = $event->broadcastOn()[0];

            return $channel instanceof PrivateChannel && $channel->name === 'private-test-channel';
        });
    }

    public function testSendToPresenceChannel(): void
    {
        Event::fake();

        Broadcast::presence('test-channel')->send();

        Event::assertDispatched(AnonymousEvent::class, function ($event) {
            $channel = $event->broadcastOn()[0];

            return $channel instanceof PresenceChannel && $channel->name === 'presence-test-channel';
        });
    }
}
