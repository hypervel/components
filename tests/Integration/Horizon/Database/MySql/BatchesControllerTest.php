<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Horizon\Database\MySql;

use Hypervel\Bus\BatchRepository;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Horizon\HorizonServiceProvider;
use Hypervel\Horizon\Http\Controllers\BatchesController;
use Hypervel\Http\Request;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Schema;
use Hypervel\Tests\Integration\Database\MySql\MySqlTestCase;

class BatchesControllerTest extends MySqlTestCase
{
    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [
            ...parent::getPackageProviders($app),
            HorizonServiceProvider::class,
        ];
    }

    protected function defineEnvironment(ApplicationContract $app): void
    {
        parent::defineEnvironment($app);

        $config = $app->make('config');
        $config->set('queue.batching.database', $config->string('database.default'));
    }

    protected function afterRefreshingDatabase(): void
    {
        Schema::create('job_batches', static function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('name');
            $table->string('driver')->default('database');
            $table->integer('total_jobs');
            $table->integer('pending_jobs');
            $table->integer('failed_jobs');
            $table->integer('outstanding_failed_jobs');
            $table->mediumText('options')->nullable();
            $table->integer('cancelled_at')->nullable();
            $table->integer('created_at');
            $table->integer('finished_at')->nullable();
        });

        $this->insertBatch('plain', 'Import Users');
        $this->insertBatch('percent', 'Import%Users');
        $this->insertBatch('underscore', 'Import_Users');
        $this->insertBatch('wildcard-decoy', 'ImportXUsers');
    }

    protected function destroyDatabaseMigrations(): void
    {
        Schema::dropIfExists('job_batches');
    }

    public function testBatchSearchUsesPortableLiteralWildcardEscaping(): void
    {
        $controller = new BatchesController($this->app->make(BatchRepository::class));

        $plain = $controller->index(Request::create('/?query=Import Users'));
        $percent = $controller->index(Request::create('/?query=%25'));
        $underscore = $controller->index(Request::create('/?query=_'));

        $this->assertSame(['plain'], array_column($plain['batches'], 'id'));
        $this->assertSame(['percent'], array_column($percent['batches'], 'id'));
        $this->assertSame(['underscore'], array_column($underscore['batches'], 'id'));
    }

    private function insertBatch(string $id, string $name): void
    {
        DB::table('job_batches')->insert([
            'id' => $id,
            'name' => $name,
            'total_jobs' => 0,
            'pending_jobs' => 0,
            'failed_jobs' => 0,
            'outstanding_failed_jobs' => 0,
            'options' => serialize([]),
            'created_at' => time(),
            'cancelled_at' => null,
            'finished_at' => null,
        ]);
    }
}
