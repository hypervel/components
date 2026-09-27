<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Mail;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Foundation\Testing\LazilyRefreshDatabase;
use Hypervel\Notifications\Events\NotificationSent;
use Hypervel\Notifications\Notifiable;
use Hypervel\Support\Facades\Event;
use Hypervel\Support\Facades\Schema;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Notifications\Fixtures\SentMessageMailNotification;

class SentMessageMailTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function afterRefreshingDatabase(): void
    {
        Schema::create('sent_message_users', function (Blueprint $table): void {
            $table->increments('id');
        });
    }

    protected function beforeRefreshingDatabase(): void
    {
        Schema::dropIfExists('sent_message_users');
    }

    public function testDispatchesNotificationSent(): void
    {
        $notificationWasSent = false;

        $user = SentMessageUser::create();

        Event::listen(
            NotificationSent::class,
            function (NotificationSent $notification) use (&$notificationWasSent, $user): void {
                $notificationWasSent = true;
                /**
                 * Confirm that NotificationSent can be serialized/unserialized as
                 * will happen if the listener implements ShouldQueue.
                 */
                /** @var NotificationSent $afterSerialization */
                $afterSerialization = unserialize(serialize($notification));

                $this->assertTrue($user->is($afterSerialization->notifiable));

                $this->assertEqualsCanonicalizing($notification->notification, $afterSerialization->notification);
            }
        );

        $user->notify(new SentMessageMailNotification);

        $this->assertTrue($notificationWasSent);
    }
}

class SentMessageUser extends Model
{
    use Notifiable;

    public bool $timestamps = false;
}
