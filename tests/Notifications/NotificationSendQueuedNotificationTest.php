<?php

declare(strict_types=1);

namespace Hypervel\Tests\Notifications;

use Hypervel\Contracts\Database\ModelIdentifier;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Notifications\AnonymousNotifiable;
use Hypervel\Notifications\ChannelManager;
use Hypervel\Notifications\Notifiable;
use Hypervel\Notifications\Notification;
use Hypervel\Notifications\SendQueuedNotifications;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Collection;
use Hypervel\Tests\TestCase;
use Mockery as m;

class NotificationSendQueuedNotificationTest extends TestCase
{
    public function testNotificationsCanBeSent(): void
    {
        $notification = new TestNotification;
        $job = new SendQueuedNotifications('notifiables', $notification);
        $manager = m::mock(ChannelManager::class);
        $manager->shouldReceive('sendNow')->once()->withArgs(function ($notifiables, $notification, $channels) {
            return $notifiables instanceof Collection && $notifiables->toArray() === ['notifiables']
                && $notification instanceof TestNotification
                && $channels === null;
        });
        $job->handle($manager);
    }

    public function testSerializationOfNotifiableModel(): void
    {
        $notifiable = (new NotifiableUser)->forceFill(['id' => 1]);
        $identifier = new ModelIdentifier(NotifiableUser::class, [1], [], null);
        $serializedIdentifier = serialize($identifier);

        $job = new SendQueuedNotifications($notifiable, new TestNotification);
        $serialized = serialize($job);

        $this->assertStringContainsString($serializedIdentifier, $serialized);
    }

    public function testSerializationOfNormalNotifiable(): void
    {
        $notifiable = new AnonymousNotifiable;
        $serializedNotifiable = serialize($notifiable);

        $job = new SendQueuedNotifications($notifiable, new TestNotification);
        $serialized = serialize($job);

        $this->assertStringContainsString($serializedNotifiable, $serialized);
    }

    public function testNotificationCanSetMaxExceptions(): void
    {
        $notifiable = new NotifiableUser;
        $notification = new class {
            public int $maxExceptions = 23;
        };

        $job = new SendQueuedNotifications($notifiable, $notification);

        $this->assertEquals(23, $job->maxExceptions);
    }

    public function testNotificationAcceptsImmutableRetryUntilMethod(): void
    {
        $retryUntil = CarbonImmutable::parse('2026-07-23 12:34:56');
        $notification = new TestNotificationWithRetryUntil($retryUntil);

        $this->assertSame(
            $retryUntil,
            (new SendQueuedNotifications('notifiable', $notification))->retryUntil()
        );
    }
}

class NotifiableUser extends Model
{
    use Notifiable;

    protected ?string $table = 'users';

    public bool $timestamps = false;
}

class TestNotification extends Notification
{
}

class TestNotificationWithRetryUntil extends Notification
{
    public function __construct(
        private CarbonImmutable $retryUntil
    ) {
    }

    public function retryUntil(): CarbonImmutable
    {
        return $this->retryUntil;
    }
}
