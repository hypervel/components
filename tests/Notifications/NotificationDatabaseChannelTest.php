<?php

declare(strict_types=1);

namespace Hypervel\Tests\Notifications;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\HasMany;
use Hypervel\Notifications\AnonymousNotifiable;
use Hypervel\Notifications\Channels\DatabaseChannel;
use Hypervel\Notifications\Messages\DatabaseMessage;
use Hypervel\Notifications\Notification;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;

class NotificationDatabaseChannelTest extends TestCase
{
    use VerifiesDoubles;

    public function testDatabaseChannelCreatesDatabaseRecordWithProperData(): void
    {
        $notification = new NotificationDatabaseChannelTestNotification;
        $notification->id = '1';
        $notifiable = Double::for(AnonymousNotifiable::class);

        $notifiable->expects('routeNotificationFor')->returns($relation = Double::for(HasMany::class));
        $relation->expects('create')->with([
            'id' => '1',
            'type' => get_class($notification),
            'data' => ['invoice_id' => '1'],
            'read_at' => null,
        ])->returns(Double::for(Model::class));

        $channel = new DatabaseChannel;
        $channel->send($notifiable, $notification);
    }

    public function testCorrectPayloadIsSentToDatabase(): void
    {
        $notification = new NotificationDatabaseChannelTestNotification;
        $notification->id = '1';
        $notifiable = Double::for(AnonymousNotifiable::class);

        $notifiable->expects('routeNotificationFor')->returns($relation = Double::for(HasMany::class));
        $relation->expects('create')->with([
            'id' => '1',
            'type' => get_class($notification),
            'data' => ['invoice_id' => '1'],
            'read_at' => null,
            'something' => 'else',
        ])->returns(Double::for(Model::class));

        $channel = new ExtendedDatabaseChannel;
        $channel->send($notifiable, $notification);
    }

    public function testCustomizeTypeIsSentToDatabase(): void
    {
        CarbonImmutable::setTestNow('2026-08-05 12:00:00');

        $notification = new NotificationDatabaseChannelCustomizeTypeTestNotification;
        $notification->id = '1';
        $notifiable = Double::for(AnonymousNotifiable::class);

        $notifiable->expects('routeNotificationFor')->returns($relation = Double::for(HasMany::class));
        $relation->expects('create')->with([
            'id' => '1',
            'type' => 'MONTHLY',
            'data' => ['invoice_id' => '1'],
            'read_at' => CarbonImmutable::now(),
            'something' => 'else',
        ])->returns(Double::for(Model::class));

        $channel = new ExtendedDatabaseChannel;
        $channel->send($notifiable, $notification);
    }
}

class NotificationDatabaseChannelTestNotification extends Notification
{
    /**
     * Get the database representation of the notification.
     */
    public function toDatabase(mixed $notifiable): DatabaseMessage
    {
        return new DatabaseMessage(['invoice_id' => '1']);
    }
}

class NotificationDatabaseChannelCustomizeTypeTestNotification extends Notification
{
    /**
     * Get the database representation of the notification.
     */
    public function toDatabase(mixed $notifiable): DatabaseMessage
    {
        return new DatabaseMessage(['invoice_id' => '1']);
    }

    /**
     * Get the notification type stored in the database.
     */
    public function databaseType(): string
    {
        return 'MONTHLY';
    }

    /**
     * Get the initial read timestamp.
     */
    public function initialDatabaseReadAtValue(): CarbonImmutable
    {
        return CarbonImmutable::now();
    }
}

class ExtendedDatabaseChannel extends DatabaseChannel
{
    /**
     * Build the database notification payload.
     */
    protected function buildPayload(mixed $notifiable, Notification $notification): array
    {
        return array_merge(parent::buildPayload($notifiable, $notification), [
            'something' => 'else',
        ]);
    }
}
