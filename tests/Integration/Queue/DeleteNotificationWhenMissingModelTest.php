<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Queue\DeleteNotificationWhenMissingModelTest;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Notifications\Notifiable;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Notification as NotificationFacade;
use Hypervel\Support\Facades\Schema;
use Hypervel\Testbench\Attributes\WithMigration;
use Hypervel\Tests\Integration\Queue\QueueTestCase;
use Hypervel\Tests\Notifications\Fixtures\DeleteWhenMissingNotification;
use Override;

#[WithMigration]
#[WithMigration('queue')]
class DeleteNotificationWhenMissingModelTest extends QueueTestCase
{
    protected function defineEnvironment(ApplicationContract $app): void
    {
        parent::defineEnvironment($app);
        $app->make('config')->set('queue.default', 'database');
    }

    protected function defineDatabaseMigrationsAfterDatabaseRefreshed(): void
    {
        Schema::create('delete_notification_test_models', function (Blueprint $table) {
            $table->id();
            $table->string('name');
        });
    }

    protected function destroyDatabaseMigrations(): void
    {
        Schema::dropIfExists('delete_notification_test_models');
    }

    #[Override]
    protected function tearDown(): void
    {
        DeleteWhenMissingNotification::$sent = false;

        parent::tearDown();
    }

    public function testDeleteModelWhenMissingOnQueuedNotification(): void
    {
        $model = DeleteNotificationTestModel::query()->create(['name' => 'test']);

        NotificationFacade::send($model, new DeleteWhenMissingNotification($model));

        DeleteNotificationTestModel::query()->where('name', 'test')->delete();

        $this->runQueueWorkerCommand(['--once' => '1']);

        $this->assertFalse(DeleteWhenMissingNotification::$sent);
        $this->assertNull(DB::table('failed_jobs')->first());
    }
}

class DeleteNotificationTestModel extends Model
{
    use Notifiable;

    protected ?string $table = 'delete_notification_test_models';

    public bool $timestamps = false;

    protected array $guarded = [];
}
