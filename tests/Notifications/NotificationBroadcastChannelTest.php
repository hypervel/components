<?php

declare(strict_types=1);

namespace Hypervel\Tests\Notifications;

use Hypervel\Broadcasting\PrivateChannel;
use Hypervel\Notifications\Channels\BroadcastChannel;
use Hypervel\Notifications\Events\BroadcastNotificationCreated;
use Hypervel\Notifications\Messages\BroadcastMessage;
use Hypervel\Notifications\Notification;
use Mockery as m;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * @internal
 * @coversNothing
 */
class NotificationBroadcastChannelTest extends TestCase
{
    protected function tearDown(): void
    {
        m::close();
    }

    public function testDatabaseChannelCreatesDatabaseRecordWithProperData()
    {
        $notification = new NotificationBroadcastChannelTestNotification();
        $notification->id = '1';
        $notifiable = m::mock();

        $events = m::mock(EventDispatcherInterface::class);
        $events->shouldReceive('dispatch')->once()->with(m::type(BroadcastNotificationCreated::class));
        $channel = new BroadcastChannel($events);
        $channel->send($notifiable, $notification);
    }

    public function testNotificationIsBroadcastedOnCustomChannels()
    {
        $notification = new CustomChannelsTestNotification();
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

    public function testNotificationIsBroadcastedWithCustomEventName()
    {
        $notification = new CustomEventNameTestNotification();
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

    public function testNotificationIsBroadcastedWithCustomDataType()
    {
        $notification = new CustomEventNameTestNotification();
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

    public function testNotificationIsBroadcastedNow()
    {
        $notification = new TestNotificationBroadCastedNow();
        $notification->id = '1';
        $notifiable = m::mock();

        $events = m::mock(EventDispatcherInterface::class);
        $events->shouldReceive('dispatch')->once()->with(m::on(function ($event) {
            return $event->connection === 'sync';
        }));
        $channel = new BroadcastChannel($events);
        $channel->send($notifiable, $notification);
    }

    public function testNotificationIsBroadcastedWithCustomAdditionalPayload()
    {
        $notification = new CustomBroadcastWithTestNotification();
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
    public function toArray($notifiable)
    {
        return ['invoice_id' => 1];
    }
}

class CustomChannelsTestNotification extends Notification
{
    public function toArray($notifiable)
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
    public function toArray($notifiable)
    {
        return ['invoice_id' => 1];
    }

    public function broadcastType()
    {
        return 'custom.type';
    }
}

class TestNotificationBroadCastedNow extends Notification
{
    public function toArray($notifiable)
    {
        return ['invoice_id' => 1];
    }

    public function toBroadcast()
    {
        return (new BroadcastMessage([]))->onConnection('sync');
    }
}

class CustomBroadcastWithTestNotification extends Notification
{
    public function toArray($notifiable)
    {
        return ['invoice_id' => 1];
    }

    public function broadcastWith()
    {
        return ['id' => 1, 'type' => 'custom', 'additional' => 'custom'];
    }
}
