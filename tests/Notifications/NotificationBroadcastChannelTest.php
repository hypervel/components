<?php

declare(strict_types=1);

namespace Hypervel\Tests\Notifications;

use Hypervel\Broadcasting\PrivateChannel;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Notifications\Channels\BroadcastChannel;
use Hypervel\Notifications\Events\BroadcastNotificationCreated;
use Hypervel\Notifications\Messages\BroadcastMessage;
use Hypervel\Notifications\Notification;
use Hypervel\Tests\TestCase;
use Mockery as m;

class NotificationBroadcastChannelTest extends TestCase
{
    public function testDatabaseChannelCreatesDatabaseRecordWithProperData(): void
    {
        $notification = new NotificationBroadcastChannelTestNotification;
        $notification->id = '1';
        $notifiable = m::mock();

        $events = m::mock(Dispatcher::class);
        $events->shouldReceive('dispatch')->once()->with(m::type(BroadcastNotificationCreated::class));
        $channel = new BroadcastChannel($events);
        $channel->send($notifiable, $notification);
    }

    public function testNotificationIsBroadcastedOnCustomChannels(): void
    {
        $notification = new CustomChannelsTestNotification;
        $notification->id = '1';
        $notifiable = m::mock();

        $event = new BroadcastNotificationCreated(
            $notifiable,
            $notification,
            $notification->toArray($notifiable)
        );

        $channels = $event->broadcastOn();

        $this->assertEquals(new PrivateChannel('custom-channel'), $channels[0]);
    }

    public function testNotificationIsBroadcastedWithCustomEventName(): void
    {
        $notification = new CustomEventNameTestNotification;
        $notification->id = '1';
        $notifiable = m::mock();

        $event = new BroadcastNotificationCreated(
            $notifiable,
            $notification,
            $notification->toArray($notifiable)
        );

        $eventName = $event->broadcastType();

        $this->assertSame('custom.type', $eventName);
    }

    public function testNotificationIsBroadcastedWithCustomDataType(): void
    {
        $notification = new CustomEventNameTestNotification;
        $notification->id = '1';
        $notifiable = m::mock();

        $event = new BroadcastNotificationCreated(
            $notifiable,
            $notification,
            $notification->toArray($notifiable)
        );

        $data = $event->broadcastWith();

        $this->assertSame('custom.type', $data['type']);
    }

    public function testNotificationIsBroadcastedNow(): void
    {
        $notification = new TestNotificationBroadCastedNow;
        $notification->id = '1';
        $notifiable = m::mock();

        $events = m::mock(Dispatcher::class);
        $events->shouldReceive('dispatch')->once()->with(m::on(function ($event) {
            return $event->connection === 'sync';
        }));
        $channel = new BroadcastChannel($events);
        $channel->send($notifiable, $notification);
    }

    public function testNotificationIsBroadcastedWithCustomAdditionalPayload(): void
    {
        $notification = new CustomBroadcastWithTestNotification;
        $notification->id = '1';
        $notifiable = m::mock();

        $event = new BroadcastNotificationCreated(
            $notifiable,
            $notification,
            $notification->toArray($notifiable)
        );

        $data = $event->broadcastWith();

        $this->assertArrayHasKey('additional', $data);
    }
}

class NotificationBroadcastChannelTestNotification extends Notification
{
    public function toArray(mixed $notifiable): array
    {
        return ['invoice_id' => 1];
    }
}

class CustomChannelsTestNotification extends Notification
{
    public function toArray(mixed $notifiable): array
    {
        return ['invoice_id' => 1];
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel('custom-channel')];
    }
}

class CustomEventNameTestNotification extends Notification
{
    public function toArray(mixed $notifiable): array
    {
        return ['invoice_id' => 1];
    }

    public function broadcastType(): string
    {
        return 'custom.type';
    }
}

class TestNotificationBroadCastedNow extends Notification
{
    public function toArray(mixed $notifiable): array
    {
        return ['invoice_id' => 1];
    }

    public function toBroadcast(): BroadcastMessage
    {
        return (new BroadcastMessage([]))->onConnection('sync');
    }
}

class CustomBroadcastWithTestNotification extends Notification
{
    public function toArray(mixed $notifiable): array
    {
        return ['invoice_id' => 1];
    }

    public function broadcastWith(): array
    {
        return ['id' => 1, 'type' => 'custom', 'additional' => 'custom'];
    }
}
