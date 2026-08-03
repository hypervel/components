<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Notifications;

use Hypervel\Database\Eloquent\Casts\AsStringable;
use Hypervel\Database\Eloquent\Concerns\HasUuids;
use Hypervel\Database\Eloquent\MissingAttributeException;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Notifications\DatabaseNotification;
use Hypervel\Notifications\Notifiable;
use Hypervel\Support\Facades\Notification;
use Hypervel\Support\Facades\Schema;
use Hypervel\Testbench\Attributes\DefineDatabase;
use Hypervel\Testbench\Attributes\WithMigration;
use Hypervel\Testbench\TestCase;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;

#[WithMigration('hypervel', 'notifications')]
class DatabaseNotificationTest extends TestCase
{
    use RefreshDatabase;

    #[DefineDatabase('defineDatabaseAndConvertUserIdToUuid')]
    public function testAssertSentToWhenNotifiableHasStringableKey()
    {
        Notification::fake();

        $user = UuidUserFactoryStub::new()->create();

        $user->notify(new NotificationStub);

        Notification::assertSentTo($user, NotificationStub::class, function ($notification, $channels, $notifiable) use ($user) {
            return $notifiable === $user;
        });
    }

    #[DataProvider('notificationStateMethodProvider')]
    public function testPersistedNotificationsRejectMissingReadStateBeforeMutation(string $method): void
    {
        Model::preventAccessingMissingAttributes(false);

        $notification = new TrackedDatabaseNotification;
        $notification->exists = true;
        $notification->setRawAttributes(['id' => 'notification-id'], true);

        try {
            $notification->{$method}();
            $this->fail('Expected a missing read state exception was not thrown.');
        } catch (MissingAttributeException $exception) {
            $this->assertStringContainsString('read_at', $exception->getMessage());
        }

        $this->assertSame(0, $notification->saveCalls);
    }

    public static function notificationStateMethodProvider(): array
    {
        return [
            'mark as read' => ['markAsRead'],
            'mark as unread' => ['markAsUnread'],
            'read predicate' => ['read'],
            'unread predicate' => ['unread'],
        ];
    }

    public function testLoadedNotificationReadStateRetainsPredicatesAndMutations(): void
    {
        $notification = new TrackedDatabaseNotification;
        $notification->exists = true;
        $notification->setRawAttributes(['read_at' => null], true);

        $this->assertFalse($notification->read());
        $this->assertTrue($notification->unread());

        $notification->markAsRead();

        $this->assertSame(1, $notification->saveCalls);
        $this->assertTrue($notification->read());
        $this->assertFalse($notification->unread());

        $notification->markAsUnread();

        $this->assertSame(2, $notification->saveCalls);
        $this->assertFalse($notification->read());
        $this->assertTrue($notification->unread());
    }

    public function testFreshAndJustCreatedNotificationsRetainUnreadDefaults(): void
    {
        $fresh = new TrackedDatabaseNotification;

        $this->assertTrue($fresh->unread());

        $justCreated = new TrackedDatabaseNotification;
        $justCreated->exists = true;
        $justCreated->wasRecentlyCreated = true;

        $this->assertTrue($justCreated->unread());
    }

    /**
     * Define database and convert User's ID to UUID.
     */
    protected function defineDatabaseAndConvertUserIdToUuid(mixed $app): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->uuid('id')->change();
        });
    }
}

class TrackedDatabaseNotification extends DatabaseNotification
{
    public int $saveCalls = 0;

    public function save(array $options = []): bool
    {
        ++$this->saveCalls;

        return true;
    }
}

class UuidUserFactoryStub extends \Hypervel\Testbench\Factories\UserFactory
{
    protected ?string $model = UuidUserStub::class;
}

class UuidUserStub extends \Hypervel\Foundation\Auth\User
{
    use HasUuids;
    use Notifiable;

    protected ?string $table = 'users';

    #[Override]
    public function casts(): array
    {
        return array_merge(parent::casts(), ['id' => AsStringable::class]);
    }
}

class NotificationStub extends \Hypervel\Notifications\Notification
{
    public function via($notifiable)
    {
        return ['mail'];
    }
}
