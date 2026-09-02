<?php

declare(strict_types=1);

namespace Hypervel\Tests\Notifications;

use Hypervel\Broadcasting\PrivateChannel;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Notifications\AnonymousNotifiable;
use Hypervel\Notifications\Channels\BroadcastChannel;
use Hypervel\Notifications\Events\BroadcastNotificationCreated;
use Hypervel\Notifications\Messages\BroadcastMessage;
use Hypervel\Notifications\Notification;
use Hypervel\Tests\TestCase;
use LogicException;
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
        $notifiable = new AnonymousNotifiable;

        $event = new BroadcastNotificationCreated(
            $notifiable,
            $notification,
            $notification->toArray($notifiable)
        );

        $channels = $event->broadcastOn();

        $this->assertEquals(new PrivateChannel('custom-channel'), $channels[0]);
    }

    public function testAnonymousNotificationWithoutBroadcastRouteThrows(): void
    {
        $event = new BroadcastNotificationCreated(
            new AnonymousNotifiable,
            new Notification,
        );

        $this->expectExceptionObject(new LogicException(
            'Anonymous notifiables must define an explicit broadcast route or the notification must define a broadcast channel.'
        ));

        $event->broadcastOn();
    }

    public function testAnonymousNotificationUsesExplicitBroadcastRoute(): void
    {
        $notifiable = (new AnonymousNotifiable)->route('broadcast', 'custom-route');
        $event = new BroadcastNotificationCreated($notifiable, new Notification);

        $this->assertSame(['custom-route'], $event->broadcastOn());
    }

    public function testNotificationUsesNotifiableBroadcastChannel(): void
    {
        $event = new BroadcastNotificationCreated(
            new NotificationBroadcastChannelTestNotifiableWithChannel,
            new Notification,
        );

        $this->assertEquals([new PrivateChannel('notifiable-channel')], $event->broadcastOn());
    }

    public function testNotificationUsesNotifiableClassAndKeyByDefault(): void
    {
        $event = new BroadcastNotificationCreated(
            new NotificationBroadcastChannelTestNotifiable,
            new Notification,
        );

        $this->assertEquals([
            new PrivateChannel('Hypervel.Tests.Notifications.NotificationBroadcastChannelTestNotifiable.1'),
        ], $event->broadcastOn());
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

class NotificationBroadcastChannelTestNotifiable
{
    public function getKey(): int
    {
        return 1;
    }
}

class NotificationBroadcastChannelTestNotifiableWithChannel
{
    public function receivesBroadcastNotificationsOn(Notification $notification): string
    {
        return 'notifiable-channel';
    }
}
