<?php

declare(strict_types=1);

namespace Hypervel\Tests\Notifications;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\ModelIdentifier;
use Hypervel\Notifications\AnonymousNotifiable;
use Hypervel\Notifications\ChannelManager;
use Hypervel\Notifications\Notifiable;
use Hypervel\Notifications\Notification;
use Hypervel\Notifications\SendQueuedNotifications;
use Hypervel\Support\Collection;
use Hypervel\Tests\TestCase;
use Mockery as m;

/**
 * @internal
 * @coversNothing
 */
class NotificationSendQueuedNotificationTest extends TestCase
{
    public function testNotificationsCanBeSent()
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

    public function testSerializationOfNotifiableModel()
    {
        $identifier = new ModelIdentifier(NotifiableUser::class, [null], [], null);
        $serializedIdentifier = serialize($identifier);

        $job = new SendQueuedNotifications(new NotifiableUser, new TestNotification);
        $serialized = serialize($job);

        $this->assertStringContainsString($serializedIdentifier, $serialized);
    }

    public function testSerializationOfNormalNotifiable()
    {
        $notifiable = new AnonymousNotifiable;
        $serializedNotifiable = serialize($notifiable);

        $job = new SendQueuedNotifications($notifiable, new TestNotification);
        $serialized = serialize($job);

        $this->assertStringContainsString($serializedNotifiable, $serialized);
    }

    public function testNotificationCanSetMaxExceptions()
    {
        $notifiable = new NotifiableUser;
        $notification = new class {
            public int $maxExceptions = 23;
        };

        $job = new SendQueuedNotifications($notifiable, $notification);

        $this->assertEquals(23, $job->maxExceptions);
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
