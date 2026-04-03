<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Queue\DeleteModelWhenMissingTest;

use DB;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Foundation\Bus\Dispatchable;
use Hypervel\Queue\InteractsWithQueue;
use Hypervel\Queue\SerializesModels;
use Hypervel\Support\Facades\Schema;
use Hypervel\Testbench\Attributes\WithMigration;
use Hypervel\Tests\Integration\Queue\QueueTestCase;
use Override;

/**
 * @internal
 * @coversNothing
 */
#[WithMigration]
#[WithMigration('queue')]
class DeleteModelWhenMissingTest extends QueueTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('queue.default', 'database');
        $this->driver = 'database';
    }

    protected function defineDatabaseMigrationsAfterDatabaseRefreshed(): void
    {
        Schema::create('delete_model_test_models', function (Blueprint $table) {
            $table->id();
            $table->string('name');
        });
    }

    protected function destroyDatabaseMigrations(): void
    {
        Schema::dropIfExists('delete_model_test_models');
    }

    #[Override]
    protected function tearDown(): void
    {
        DeleteMissingModelJob::$handled = false;

        parent::tearDown();
    }

    public function testDeleteModelWhenMissingAndDisplayName(): void
    {
        $model = MyTestModel::query()->create(['name' => 'test']);

        DeleteMissingModelJob::dispatch($model);

        MyTestModel::query()->where('name', 'test')->delete();

        $this->runQueueWorkerCommand(['--once' => '1']);

        $this->assertFalse(DeleteMissingModelJob::$handled);
        $this->assertNull(DB::table('failed_jobs')->first());
    }
}

class DeleteMissingModelJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Dispatchable;
    use SerializesModels;

    public static bool $handled = false;

    public bool $deleteWhenMissingModels = true;

    public function __construct(public MyTestModel $model)
    {
    }

    public function displayName(): string
    {
        return 'sorry-ma-forgot-to-take-out-the-trash';
    }

    public function handle(): void
    {
        self::$handled = true;
    }
}

class MyTestModel extends Model
{
    protected ?string $table = 'delete_model_test_models';

    public bool $timestamps = false;

    protected array $guarded = [];
}
