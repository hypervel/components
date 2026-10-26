<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue\Outbox;

use Hypervel\Bus\Batchable;
use Hypervel\Bus\Queueable;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Contracts\Redis\Factory;
use Hypervel\Support\Facades\Bus;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Schema;
use Hypervel\Testbench\TestCase;
use LogicException;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;

class OutboxBatchTest extends TestCase
{
    /**
     * Keep batch counters and a conflicting business transaction on separate sessions.
     */
    protected function defineEnvironment(Application $app): void
    {
        $config = $app->make('config');
        $config->set('database.default', 'batch');
        $config->set('database.connections.batch', ['driver' => 'sqlite', 'database' => ':memory:']);
        $config->set('database.connections.business', ['driver' => 'sqlite', 'database' => ':memory:']);
        $config->set('queue.batching.database', 'batch');
        $config->set('queue.outbox.databases', ['batch', 'business']);
        $config->set('queue.connections.database', [
            'driver' => 'database', 'connection' => 'business', 'table' => 'jobs',
            'queue' => 'default', 'after_commit' => false,
        ]);
    }

    /**
     * Install the shipped operational schemas without contacting a broker.
     */
    protected function setUpInCoroutine(): void
    {
        $migrations = dirname(__DIR__, 3) . '/src/testbench/hypervel/migrations/';
        Schema::swap(DB::connection('batch')->getSchemaBuilder());
        (require $migrations . '0001_01_01_000005_testbench_create_job_batches_table.php')->up();
        (require $migrations . '0001_01_01_000009_testbench_create_job_outbox_table.php')->up();
        Schema::swap(DB::connection('business')->getSchemaBuilder());
        (require $migrations . '0001_01_01_000006_testbench_create_jobs_table.php')->up();
        (require $migrations . '0001_01_01_000009_testbench_create_job_outbox_table.php')->up();
        $this->app->instance('redis', m::mock(Factory::class));
    }

    #[DataProvider('queueConnections')]
    public function testConflictingOwnerCannotChangeAnExistingBatch(string $connection): void
    {
        $batch = Bus::batch([])->onConnection($connection)->onOutboxDatabase('business')->dispatch();
        DB::connection('business')->beginTransaction();

        try {
            $batch->add([new OutboxBatchJob]);
            $this->fail('An outbox owner cannot differ from the batch counter transaction.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('Outbox database [business] does not match the batch database [batch]', $exception->getMessage());
            $this->assertSame(0, $batch->fresh()->totalJobs);
            $this->assertSame(0, $batch->fresh()->pendingJobs);
            $this->assertSame(0, DB::connection('batch')->table('job_outbox')->count());
            $this->assertSame(0, DB::connection('business')->table('job_outbox')->count());
            $this->assertSame(0, DB::connection('business')->table('jobs')->count());
        } finally {
            DB::connection('business')->rollBack();
        }
    }

    /**
     * Cover staged delivery, the database fast path and local after-commit delivery.
     */
    public static function queueConnections(): array
    {
        return ['staged' => ['redis'], 'database' => ['database'], 'local' => ['sync']];
    }

    public function testSameOwnerRollsBackCountersAndStagedJobsTogether(): void
    {
        $batch = Bus::batch([])->onConnection('redis')->onOutboxDatabase('batch')->dispatch();
        DB::beginTransaction();

        try {
            $batch->add([new OutboxBatchJob]);
            $this->assertSame(1, $batch->fresh()->totalJobs);
            $this->assertSame(1, $batch->fresh()->pendingJobs);
            $this->assertSame(1, DB::table('job_outbox')->count());
        } finally {
            DB::rollBack();
        }

        $this->assertSame(0, $batch->fresh()->totalJobs);
        $this->assertSame(0, $batch->fresh()->pendingJobs);
        $this->assertSame(0, DB::table('job_outbox')->count());
    }
}

class OutboxBatchJob
{
    use Batchable;
    use Queueable;
}
