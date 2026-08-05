<?php

declare(strict_types=1);

namespace Hypervel\Tests\Notifications;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Notifications\Channels\DatabaseChannel;
use Hypervel\Notifications\Messages\DatabaseMessage;
use Hypervel\Notifications\Notification;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Tests\TestCase;
use Mockery as m;

class NotificationDatabaseChannelTest extends TestCase
{
    public function testDatabaseChannelCreatesDatabaseRecordWithProperData(): void
    {
        $notification = new NotificationDatabaseChannelTestNotification;
        $notification->id = '1';
        $notifiable = m::mock();

        $notifiable->shouldReceive('routeNotificationFor->create')->with([
            'id' => 1,
            'type' => get_class($notification),
            'data' => ['invoice_id' => '1'],
            'read_at' => null,
        ])->andReturn(m::mock(Model::class));

        $channel = new DatabaseChannel;
        $channel->send($notifiable, $notification);
    }

    public function testCorrectPayloadIsSentToDatabase(): void
    {
        $notification = new NotificationDatabaseChannelTestNotification;
        $notification->id = '1';
        $notifiable = m::mock();

        $notifiable->shouldReceive('routeNotificationFor->create')->with([
            'id' => 1,
            'type' => get_class($notification),
            'data' => ['invoice_id' => '1'],
            'read_at' => null,
            'something' => 'else',
        ])->andReturn(m::mock(Model::class));

        $channel = new ExtendedDatabaseChannel;
        $channel->send($notifiable, $notification);
    }

    public function testCustomizeTypeIsSentToDatabase(): void
    {
        CarbonImmutable::setTestNow('2026-08-05 12:00:00');

        $notification = new NotificationDatabaseChannelCustomizeTypeTestNotification;
        $notification->id = '1';
        $notifiable = m::mock();

        $notifiable->shouldReceive('routeNotificationFor->create')->with([
            'id' => '1',
            'type' => 'MONTHLY',
            'data' => ['invoice_id' => '1'],
            'read_at' => CarbonImmutable::now()->toDateTimeString(),
            'something' => 'else',
        ])->andReturn(m::mock(Model::class));

        $channel = new ExtendedDatabaseChannel;
        $channel->send($notifiable, $notification);
    }
}

class NotificationDatabaseChannelTestNotification extends Notification
{
    public function toDatabase(mixed $notifiable): DatabaseMessage
    {
        return new DatabaseMessage(['invoice_id' => '1']);
    }
}

class NotificationDatabaseChannelCustomizeTypeTestNotification extends Notification
{
    public function toDatabase(mixed $notifiable): DatabaseMessage
    {
        return new DatabaseMessage(['invoice_id' => '1']);
    }

    public function databaseType(): string
    {
        return 'MONTHLY';
    }

    public function initialDatabaseReadAtValue(): CarbonImmutable
    {
        return CarbonImmutable::now();
    }
}

class ExtendedDatabaseChannel extends DatabaseChannel
{
    protected function buildPayload($notifiable, Notification $notification): array
    {
        return array_merge(parent::buildPayload($notifiable, $notification), [
            'something' => 'else',
        ]);
    }
}
