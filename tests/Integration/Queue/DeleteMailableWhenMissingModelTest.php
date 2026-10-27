<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Queue;

use Hypervel\Bus\Queueable;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Mail\Mailable;
use Hypervel\Queue\Attributes\DeleteWhenMissingModels;
use Hypervel\Queue\SerializesModels;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Mail;
use Hypervel\Support\Facades\Schema;
use Hypervel\Testbench\Attributes\WithMigration;

#[WithMigration]
#[WithMigration('queue')]
class DeleteMailableWhenMissingModelTest extends QueueTestCase
{
    /**
     * Define the application environment.
     */
    protected function defineEnvironment(ApplicationContract $app): void
    {
        parent::defineEnvironment($app);
        $app->make('config')->set('queue.default', 'database');
        $app->make('config')->set('mail.default', 'array');
    }

    /**
     * Create the model table after refreshing the database.
     */
    protected function defineDatabaseMigrationsAfterDatabaseRefreshed(): void
    {
        Schema::create('delete_mailable_test_models', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
    }

    /**
     * Remove the model table.
     */
    protected function destroyDatabaseMigrations(): void
    {
        Schema::dropIfExists('delete_mailable_test_models');
    }

    public function testDeleteModelWhenMissingOnQueuedMailable(): void
    {
        $model = DeleteMailableTestModel::query()->create(['name' => 'test']);

        Mail::to('taylor@hypervel.com')->queue(new DeleteWhenMissingMailable($model));

        DeleteMailableTestModel::query()->where('name', 'test')->delete();

        $this->runQueueWorkerCommand(['--once' => '1']);

        $this->assertNull(DB::table('failed_jobs')->first());
        $this->assertNull(DB::table('jobs')->first());
        $this->assertCount(0, Mail::mailer('array')->getSymfonyTransport()->messages());
    }
}

class DeleteMailableTestModel extends Model
{
    protected ?string $table = 'delete_mailable_test_models';

    public bool $timestamps = false;

    protected array $guarded = [];
}

#[DeleteWhenMissingModels]
class DeleteWhenMissingMailable extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    /**
     * Create a new mailable instance.
     */
    public function __construct(public DeleteMailableTestModel $model)
    {
    }

    /**
     * Build the message.
     */
    public function build(): static
    {
        return $this->subject('Test')->html('test');
    }
}
