<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Notifications;

use Hypervel\Database\Eloquent\Casts\AsStringable;
use Hypervel\Database\Eloquent\Concerns\HasUuids;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Notifications\Notifiable;
use Hypervel\Support\Facades\Notification;
use Hypervel\Support\Facades\Schema;
use Hypervel\Testbench\Attributes\DefineDatabase;
use Hypervel\Testbench\Attributes\WithMigration;
use Hypervel\Testbench\TestCase;
use Override;

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
