<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Horizon\Controller;

use Hypervel\Bus\Batch;
use Hypervel\Bus\BatchFactory;
use Hypervel\Bus\BatchRepository;
use Hypervel\Bus\DatabaseBatchRepository;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Schema;
use Hypervel\Tests\Integration\Horizon\Controller\Fixtures\User;
use Hypervel\Tests\Integration\Horizon\ControllerTestCase;

class BatchesControllerTest extends ControllerTestCase
{
    public function testBatchesCanBeSearchedByName(): void
    {
        $this->setupBatchTable();
        $this->seedBatches();

        $response = $this->actingAs(new User)
            ->get('/horizon/api/batches?query=Import');

        $response->assertOk();

        $batches = $response->original['batches'];

        $this->assertCount(1, $batches);
        $this->assertSame('Import Users', $batches[0]->name);
    }

    public function testBatchesCanBeSearchedByNameCaseInsensitively(): void
    {
        $this->setupBatchTable();
        $this->seedBatches();

        $response = $this->actingAs(new User)
            ->get('/horizon/api/batches?query=import');

        $response->assertOk();

        $batches = $response->original['batches'];

        $this->assertCount(1, $batches);
        $this->assertSame('Import Users', $batches[0]->name);
    }

    public function testBatchesCanBeSearchedById(): void
    {
        $this->setupBatchTable();
        $this->seedBatches();

        $response = $this->actingAs(new User)
            ->get('/horizon/api/batches?query=batch-2');

        $response->assertOk();

        $batches = $response->original['batches'];

        $this->assertCount(1, $batches);
        $this->assertSame('Send Emails', $batches[0]->name);
    }

    public function testSearchEscapesLikeWildcards(): void
    {
        $this->setupBatchTable();
        $this->seedBatches();

        $response = $this->actingAs(new User)
            ->get('/horizon/api/batches?query=%25');

        $response->assertOk();

        $this->assertEmpty($response->original['batches']);
    }

    public function testSearchMatchesLiteralUnderscores(): void
    {
        $this->setupBatchTable();
        $this->seedBatches();
        $this->insertBatch('batch_under_score', 'Import_Users');

        $response = $this->actingAs(new User)
            ->get('/horizon/api/batches?query=_');

        $response->assertOk();

        $batches = $response->original['batches'];

        $this->assertCount(1, $batches);
        $this->assertSame('batch_under_score', $batches[0]->id);
    }

    public function testSearchSupportsCursorPagination(): void
    {
        $this->setupBatchTable();

        for ($i = 1; $i <= 3; ++$i) {
            $this->insertBatch("batch-{$i}", 'Import Chunk ' . $i);
        }

        $response = $this->actingAs(new User)
            ->get('/horizon/api/batches?query=Import&before_id=batch-3');

        $response->assertOk();

        $batches = $response->original['batches'];

        $this->assertCount(2, $batches);
        $this->assertSame('batch-2', $batches[0]->id);
        $this->assertSame('batch-1', $batches[1]->id);
    }

    public function testZeroIsASearchTerm(): void
    {
        $this->setupBatchTable();
        $this->seedBatches();
        $this->insertBatch('batch-10', 'Archive');

        $response = $this->actingAs(new User)->get('/horizon/api/batches?query=0');

        $response->assertOk();
        $this->assertSame(['batch-10'], array_column($response->original['batches'], 'id'));
    }

    public function testSearchAppliesAZeroCursor(): void
    {
        $this->setupBatchTable();
        $this->seedBatches();

        $response = $this->actingAs(new User)
            ->get('/horizon/api/batches?query=Import&before_id=0');

        $response->assertOk();
        $this->assertSame([], $response->original['batches']);
    }

    public function testSearchUsesACustomRepositoryFindMethod(): void
    {
        $this->setupBatchTable();
        $this->seedBatches();
        $this->app->instance(BatchRepository::class, new class($this->app->make(BatchFactory::class), $this->app->make('db'), 'job_batches', 'testing', 'job_batch_results') extends DatabaseBatchRepository {
            public function find(string $batchId): ?Batch
            {
                return $batchId === 'batch-1' ? null : parent::find($batchId);
            }
        });

        $response = $this->actingAs(new User)
            ->get('/horizon/api/batches?query=batch-');

        $response->assertOk();
        $this->assertSame(['batch-3', 'batch-2'], array_column($response->original['batches'], 'id'));
    }

    private function setupBatchTable(): void
    {
        $config = $this->app->make('config');

        $config->set('queue.batching.database', 'testing');
        $config->set('queue.batching.table', 'job_batches');
        $config->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);

        Schema::connection('testing')->create('job_batches', static function ($table) {
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
    }

    private function seedBatches(): void
    {
        $this->insertBatch('batch-1', 'Import Users');
        $this->insertBatch('batch-2', 'Send Emails');
        $this->insertBatch('batch-3', 'Process Orders');
    }

    private function insertBatch(string $id, string $name): void
    {
        DB::connection('testing')
            ->table('job_batches')
            ->insert([
                'id' => $id,
                'name' => $name,
                'total_jobs' => 10,
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
