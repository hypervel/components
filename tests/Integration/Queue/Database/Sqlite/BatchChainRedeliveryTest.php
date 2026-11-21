<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Queue\Database\Sqlite;

use ArrayObject;
use Hypervel\Bus\Batchable;
use Hypervel\Bus\Queueable;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Queue\Events\JobQueued;
use Hypervel\Support\Facades\Bus;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Queue;
use Hypervel\Testbench\Attributes\RequiresDatabase;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Testbench\TestCase;
use RuntimeException;

#[RequiresDatabase('sqlite')]
#[WithConfig('queue.default', 'database')]
class BatchChainRedeliveryTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Migrate the database queue, failed jobs and batch tables.
     */
    protected function migrateFreshUsing(): array
    {
        $directory = dirname(__DIR__, 5) . '/src/testbench/hypervel/migrations/';

        return ['--realpath' => true, '--path' => [
            $directory . '0001_01_01_000005_testbench_create_job_batches_table.php',
            $directory . '0001_01_01_000006_testbench_create_jobs_table.php',
            $directory . '0001_01_01_000007_testbench_create_failed_jobs_table.php',
            $directory . '0001_01_01_000010_testbench_create_job_batch_results_table.php',
        ]];
    }

    public function testReplayedHeadEnqueuesDistinctCopiesThatCountOncePerPosition(): void
    {
        $this->app->instance(ArrayObject::class, new ArrayObject);
        $batch = Bus::batch([[new BatchChainJob('A'), new BatchChainJob('B'), new BatchChainJob('C')]])->dispatch();
        $head = Queue::pop();
        $this->assertArrayNotHasKey('batchPositionId', $head->payload()['data']);
        $interruption = new RuntimeException('Successor queued; head still reserved.');
        $armed = true;
        $this->app->make('events')->listen(JobQueued::class, static function () use (&$armed, $interruption): void {
            if ($armed) {
                $armed = false;
                throw $interruption;
            }
        });
        try {
            $head->fire();
            $this->fail('Expected the head to stop after dispatching B.');
        } catch (RuntimeException $caught) {
            $this->assertSame($interruption, $caught);
        }
        $this->assertSame(3, $batch->fresh()->pendingJobs);
        DB::table('jobs')->where('id', $head->getJobId())->update(['reserved_at' => 1]);
        Queue::pop()->fire();
        $this->assertSame(2, $batch->fresh()->pendingJobs);
        foreach ([1, 0] as $pending) {
            $one = Queue::pop();
            $two = Queue::pop();
            $this->assertNotSame($one->uuid(), $two->uuid());
            $position = $one->payload()['data']['batchPositionId'];
            $this->assertSame($position, $two->payload()['data']['batchPositionId']);
            $one->fire();
            $this->assertSame($pending, $batch->fresh()->pendingJobs);
            $this->assertSame($pending === 0, $batch->fresh()->finished());
            $two->fire();
            $this->assertSame($pending, $batch->fresh()->pendingJobs);
            $this->assertSame($pending === 0, $batch->fresh()->finished());
        }
        $this->assertSame(3, DB::table('job_batch_results')->count());
    }

    public function testFailoverCopiesShareOneBatchPosition(): void
    {
        $this->app->instance(ArrayObject::class, new ArrayObject);
        $database = config('queue.connections.database');
        config([
            'queue.connections.primary' => array_replace($database, ['queue' => 'primary', 'after_commit' => false]),
            'queue.connections.secondary' => array_replace($database, ['queue' => 'secondary', 'after_commit' => false]),
            'queue.connections.failover.connections' => ['primary', 'secondary'],
            'queue.connections.failover.after_commit' => false,
        ]);
        $this->app->make('events')->listen(JobQueued::class, static function () {
            static $first = true;
            if ($first) {
                $first = false;
                throw new RuntimeException('Observer failed after the first queue accepted the job.');
            }
        });

        $batch = Bus::batch([new BatchChainJob('A'), new BatchChainJob('B')])->onConnection('failover')->dispatch();
        $original = Queue::connection('primary')->pop();
        $copy = Queue::connection('secondary')->pop();
        $this->assertNotSame($original->uuid(), $copy->uuid());
        $original->fire();
        $copy->fire();
        $this->assertSame(1, $batch->fresh()->pendingJobs);
        Queue::connection('primary')->pop()->fire();
        $this->assertTrue($batch->fresh()->finished());
    }

    public function testBatchRetryUsesTheLatestFailedCopyAndAlreadyQueuedCopiesCanStillWork(): void
    {
        $handled = new ArrayObject;
        $this->app->instance(ArrayObject::class, $handled);
        $batch = Bus::batch([[new BatchChainJob('head'), new BatchChainJob('tail')]])->allowFailures()->dispatch();
        Queue::pop()->fire();
        $first = Queue::pop();
        $command = unserialize($first->payload()['data']['command']);
        Queue::push($command);
        Queue::push($command);
        $second = Queue::pop();
        $exception = new RuntimeException('Delivery failed.');
        foreach ([$first, $second] as $job) {
            $job->fail($exception);
            $this->app->make('queue.failer')->log('database', 'default', $job->getRawBody(), $exception);
        }
        $this->app->make('queue.failer')->forget($first->uuid());
        $this->assertSame([$second->uuid()], $batch->fresh()->failedJobIds);
        $this->assertTrue($batch->fresh()->finished());
        $this->artisan('queue:retry-batch', ['id' => [$batch->id]])->assertSuccessful();
        $queued = Queue::pop();
        $retry = Queue::pop();
        $this->assertNotSame($first->uuid(), $queued->uuid());
        $this->assertSame($second->uuid(), $retry->uuid());
        $queued->fire();
        $retry->fire();
        $this->assertSame(['head', 'tail'], $handled->getArrayCopy());
        $this->assertSame(0, $batch->fresh()->pendingJobs);
        $this->assertSame([], $batch->fresh()->failedJobIds);
    }

    public function testASuccessorCanAddItselfWithoutLosingItsCurrentPosition(): void
    {
        $this->app->instance(ArrayObject::class, new ArrayObject);
        $batch = Bus::batch([[new BatchChainJob('head'), new ReenrollingSuccessor]])->dispatch();
        Queue::pop()->fire();
        Queue::pop()->fire();
        $this->assertSame(1, $batch->fresh()->pendingJobs);
        Queue::pop()->fire();
        $this->assertTrue($batch->fresh()->finished());
        $this->assertSame(3, DB::table('job_batch_results')->count());
    }

    public function testReusedHeadsAndSuccessorsKeepTheirOwnRoutesAndChains(): void
    {
        $handled = new ArrayObject;
        $this->app->instance(ArrayObject::class, $handled);
        $shared = (new BatchChainJob('shared'))->onQueue('tail-route');
        $shared->batchPositionId = 'previous-enrollment';
        $other = new BatchChainJob('other');
        $tail = new BatchChainJob('repeat');
        $batch = Bus::batch([[$shared, $tail, $tail], [$shared, new BatchChainJob('last')], $shared, [$other, $shared], [$tail, new BatchChainJob('final')]])
            ->onQueue('batch-route')->viaOutbox()->dispatch();

        $this->assertNull($shared->batchPositionId);
        $tail->assertHasChain([BatchChainJob::class]);
        $sharedTail = unserialize($other->chained[0]);
        $this->assertSame('tail-route', $sharedTail->queue);
        $this->assertNull($sharedTail->viaOutbox ?? null);
        while ($job = Queue::pop('batch-route') ?? Queue::pop('tail-route')) {
            $job->fire();
        }
        $this->assertEqualsCanonicalizing(['shared', 'repeat', 'repeat', 'shared', 'last', 'shared', 'other', 'shared', 'repeat', 'final'], $handled->getArrayCopy());
        $this->assertSame(0, $batch->fresh()->pendingJobs);
    }
}

class ReenrollingSuccessor implements ShouldQueue
{
    use Batchable;
    use Queueable;

    public function handle(): void
    {
        if ($this->batchPositionId !== null) {
            $this->batch()->add($this);
        }
    }
}

class BatchChainJob implements ShouldQueue
{
    use Batchable;
    use Queueable;

    /**
     * Create a named job for tracing chain execution.
     */
    public function __construct(public string $name)
    {
    }

    /**
     * Record work performed by an active batch job.
     */
    public function handle(ArrayObject $handled): void
    {
        if ($this->batching()) {
            $handled[] = $this->name;
        }
    }
}
