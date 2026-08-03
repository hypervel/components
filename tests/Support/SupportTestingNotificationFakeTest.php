<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support;

use Exception;
use Hypervel\Bus\Queueable;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Contracts\Translation\HasLocalePreference;
use Hypervel\Foundation\Auth\User;
use Hypervel\Notifications\AnonymousNotifiable;
use Hypervel\Notifications\Notification;
use Hypervel\Support\Collection;
use Hypervel\Support\Testing\Fakes\NotificationFake;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\ExpectationFailedException;

class SupportTestingNotificationFakeTest extends TestCase
{
    private NotificationFake $fake;

    private NotificationStub $notification;

    private UserStub $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fake = new NotificationFake;
        $this->notification = new NotificationStub;
        $this->user = new UserStub;
    }

    public function testAssertSentTo(): void
    {
        try {
            $this->fake->assertSentTo($this->user, NotificationStub::class);
            $this->fail();
        } catch (ExpectationFailedException $e) {
            $this->assertStringContainsString('The expected [Hypervel\Tests\Support\NotificationStub] notification was not sent.', $e->getMessage());
        }

        $this->fake->send($this->user, new NotificationStub);

        $this->fake->assertSentTo($this->user, NotificationStub::class);
    }

    public function testAssertSentToClosure(): void
    {
        $this->fake->send($this->user, new NotificationStub);

        $this->fake->assertSentTo($this->user, function (NotificationStub $notification): bool {
            return true;
        });
    }

    public function testAssertSentOnDemand(): void
    {
        $this->fake->send(new AnonymousNotifiable, new NotificationStub);

        $this->fake->assertSentOnDemand(NotificationStub::class);
    }

    public function testAssertSentOnDemandClosure(): void
    {
        $this->fake->send(new AnonymousNotifiable, new NotificationStub);

        $this->fake->assertSentOnDemand(NotificationStub::class, function (NotificationStub $notification): bool {
            return true;
        });
    }

    public function testAssertNotSentTo(): void
    {
        $this->fake->assertNotSentTo($this->user, NotificationStub::class);

        $this->fake->send($this->user, new NotificationStub);

        try {
            $this->fake->assertNotSentTo($this->user, NotificationStub::class);
            $this->fail();
        } catch (ExpectationFailedException $e) {
            $this->assertStringContainsString('The unexpected [Hypervel\Tests\Support\NotificationStub] notification was sent.', $e->getMessage());
        }
    }

    public function testAssertNotSentToClosure(): void
    {
        $this->fake->send($this->user, new NotificationStub);

        try {
            $this->fake->assertNotSentTo($this->user, function (NotificationStub $notification): bool {
                return true;
            });
            $this->fail();
        } catch (ExpectationFailedException $e) {
            $this->assertStringContainsString('The unexpected [Hypervel\Tests\Support\NotificationStub] notification was sent.', $e->getMessage());
        }
    }

    public function testAssertNothingSent(): void
    {
        $this->fake->assertNothingSent();
        $this->fake->send($this->user, new NotificationStub);

        try {
            $this->fake->assertNothingSent();
            $this->fail();
        } catch (ExpectationFailedException $e) {
            $this->assertStringContainsString("The following notifications were sent unexpectedly:\n\n- " . get_class(new NotificationStub), $e->getMessage());
        }
    }

    public function testAssertNothingSentTo(): void
    {
        $this->fake->assertNothingSentTo($this->user);
        $this->fake->send($this->user, new NotificationStub);

        try {
            $this->fake->assertNothingSentTo($this->user);
            $this->fail();
        } catch (ExpectationFailedException $e) {
            $this->assertStringContainsString('Notifications were sent unexpectedly.', $e->getMessage());
        }
    }

    public function testAssertSentToFailsForEmptyArray(): void
    {
        $this->expectException(Exception::class);

        $this->fake->assertSentTo([], NotificationStub::class);
    }

    public function testAssertSentToFailsForEmptyCollection(): void
    {
        $this->expectException(Exception::class);

        $this->fake->assertSentTo(new Collection, NotificationStub::class);
    }

    public function testResettingNotificationId(): void
    {
        $this->fake->send($this->user, $this->notification);

        $id = $this->notification->id;

        $this->fake->send($this->user, $this->notification);

        $this->assertSame($id, $this->notification->id);

        $this->notification->id = null;

        $this->fake->send($this->user, $this->notification);

        $this->assertNotNull($this->notification->id);
        $this->assertNotSame($id, $this->notification->id);
    }

    public function testAssertSentTimes(): void
    {
        $this->fake->assertSentTimes(NotificationStub::class, 0);

        $this->fake->send($this->user, new NotificationStub);

        $this->fake->send($this->user, new NotificationStub);

        $this->fake->send(new UserStub, new NotificationStub);

        $this->fake->assertSentTimes(NotificationStub::class, 3);
    }

    public function testAssertSentToTimes(): void
    {
        $this->fake->assertSentToTimes($this->user, NotificationStub::class, 0);

        $this->fake->send($this->user, new NotificationStub);

        $this->fake->send($this->user, new NotificationStub);

        $this->fake->send($this->user, new NotificationStub);

        $this->fake->assertSentToTimes($this->user, NotificationStub::class, 3);
    }

    public function testAssertSentOnDemandTimes(): void
    {
        $this->fake->assertSentOnDemandTimes(NotificationStub::class, 0);

        $this->fake->send(new AnonymousNotifiable, new NotificationStub);

        $this->fake->send(new AnonymousNotifiable, new NotificationStub);

        $this->fake->send(new AnonymousNotifiable, new NotificationStub);

        $this->fake->assertSentOnDemandTimes(NotificationStub::class, 3);
    }

    public function testAssertSentToWhenNotifiableHasPreferredLocale(): void
    {
        $user = new LocalizedUserStub;

        $this->fake->send($user, new NotificationStub);

        $this->fake->assertSentTo($user, NotificationStub::class, function (NotificationStub $notification, array $channels, LocalizedUserStub $notifiable, ?string $locale) use ($user): bool {
            return $notifiable === $user && $locale === 'au';
        });
    }

    public function testAssertSentToWhenNotifiableHasFalsyShouldSend(): void
    {
        $user = new LocalizedUserStub;

        $this->fake->send($user, new NotificationWithFalsyShouldSendStub);

        $this->fake->assertNotSentTo($user, NotificationWithFalsyShouldSendStub::class);
    }

    public function testAssertItCanSerializeAndRestoreNotifications(): void
    {
        $this->fake->serializeAndRestore();
        $this->fake->send($this->user, new NotificationWithSerialization('hello'));

        $this->fake->assertSentTo($this->user, NotificationWithSerialization::class, function (NotificationWithSerialization $notification): bool {
            return $notification->value === 'hello-serialized-unserialized';
        });
    }
}

class NotificationStub extends Notification
{
    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }
}

class NotificationWithFalsyShouldSendStub extends Notification
{
    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function shouldSend(mixed $notifiable, string $channel): bool
    {
        return false;
    }
}

class UserStub extends User
{
}

class LocalizedUserStub extends User implements HasLocalePreference
{
    public function preferredLocale(): string
    {
        return 'au';
    }
}

class NotificationWithSerialization extends NotificationStub implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $value)
    {
    }

    public function __serialize(): array
    {
        return ['value' => $this->value . '-serialized'];
    }

    public function __unserialize(array $data): void
    {
        $this->value = $data['value'] . '-unserialized';
    }
}
