<?php

declare(strict_types=1);

namespace Hypervel\Tests\Bus;

use Carbon\CarbonImmutable;
use Hypervel\Bus\Batch;
use Hypervel\Bus\Batchable;
use Hypervel\Bus\BatchFactory;
use Hypervel\Bus\DatabaseBatchRepository;
use Hypervel\Bus\Events\BatchCanceled;
use Hypervel\Bus\Events\BatchFinished;
use Hypervel\Bus\PendingBatch;
use Hypervel\Bus\Queueable;
use Hypervel\Contracts\Events\Dispatcher as EventDispatcher;
use Hypervel\Contracts\Queue\Factory;
use Hypervel\Contracts\Queue\Queue as QueueContract;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Database\ConnectionResolverInterface;
use Hypervel\Database\PostgresConnection;
use Hypervel\Database\Query\Builder;
use Hypervel\Foundation\Bus\Dispatchable;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Queue\CallQueuedClosure;
use Hypervel\Support\Collection;
use Hypervel\Support\Facades\Bus;
use Hypervel\Support\Facades\Queue;
use Hypervel\Testbench\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

class BusBatchTest extends TestCase
{
    use RefreshDatabase;

    protected bool $migrateRefresh = true;

    protected function migrateFreshUsing(): array
    {
        return [
            '--seed' => $this->shouldSeed(),
            '--database' => $this->getRefreshConnection(),
            '--realpath' => true,
            '--path' => __DIR__ . '/migrations',
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $_SERVER['__finally.count'] = 0;
        $_SERVER['__progress.count'] = 0;
        $_SERVER['__then.count'] = 0;
        $_SERVER['__catch.count'] = 0;
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        unset($_SERVER['__finally.batch'], $_SERVER['__progress.batch'], $_SERVER['__then.batch'], $_SERVER['__catch.batch'], $_SERVER['__catch.exception']);
    }

    public function testJobsCanBeAddedToTheBatch()
    {
        $queue = m::mock(Factory::class);

        $batch = $this->createTestBatch($queue);

        $job = new class {
            use Batchable;
        };

        $secondJob = new class {
            use Batchable;
        };

        $thirdJob = function () {
        };

        $queue->shouldReceive('connection')->once()
            ->with('test-connection')
            ->andReturn($connection = m::mock(QueueContract::class));

        $connection->shouldReceive('bulk')->once()->with(m::on(function ($args) use ($job, $secondJob) {
            return
                $args[0] == $job
                && $args[1] == $secondJob
                && $args[2] instanceof CallQueuedClosure
                && is_string($args[2]->batchId);
        }), '', 'test-queue');

        $batch = $batch->add([$job, $secondJob, $thirdJob]);

        $this->assertEquals(3, $batch->totalJobs);
        $this->assertEquals(3, $batch->pendingJobs);
        $this->assertIsString($job->batchId);
        $this->assertInstanceOf(CarbonImmutable::class, $batch->createdAt);
    }

    public function testJobsCanBeAddedToPendingBatch()
    {
        $batch = new PendingBatch($this->app, collect());
        $this->assertCount(0, $batch->jobs);

        $job = new class {
            use Batchable;
        };
        $batch->add([$job]);
        $this->assertCount(1, $batch->jobs);

        $secondJob = new class {
            use Batchable;

            public mixed $anotherProperty = null;
        };
        $batch->add($secondJob);
        $this->assertCount(2, $batch->jobs);
    }

    public function testJobsCanBeAddedToThePendingBatchFromIterable()
    {
        $batch = new PendingBatch($this->app, collect());
        $this->assertCount(0, $batch->jobs);

        $count = 3;
        $generator = function (int $jobsCount) {
            for ($i = 0; $i < $jobsCount; ++$i) {
                yield new class {
                    use Batchable;
                };
            }
        };

        $batch->add($generator($count));
        $this->assertCount($count, $batch->jobs);
    }

    public function testProcessedJobsCanBeCalculated()
    {
        $queue = m::mock(Factory::class);

        $batch = $this->createTestBatch($queue);

        $batch->totalJobs = 10;
        $batch->pendingJobs = 4;

        $this->assertEquals(6, $batch->processedJobs());
        $this->assertEquals(60, $batch->progress());
    }

    public function testSuccessfulJobsCanBeRecorded()
    {
        $queue = m::mock(Factory::class);

        $batch = $this->createTestBatch($queue);

        $job = new class {
            use Batchable;
        };

        $secondJob = new class {
            use Batchable;
        };

        $queue->shouldReceive('connection')->once()
            ->with('test-connection')
            ->andReturn($connection = m::mock(QueueContract::class));

        $connection->shouldReceive('bulk')->once();

        $batch = $batch->add([$job, $secondJob]);
        $this->assertEquals(2, $batch->pendingJobs);

        $batch->recordSuccessfulJob('test-id');
        $batch->recordSuccessfulJob('test-id');

        $this->assertInstanceOf(Batch::class, $_SERVER['__finally.batch']);
        $this->assertInstanceOf(Batch::class, $_SERVER['__progress.batch']);
        $this->assertInstanceOf(Batch::class, $_SERVER['__then.batch']);

        $batch = $batch->fresh();
        $this->assertEquals(0, $batch->pendingJobs);
        $this->assertTrue($batch->finished());
        $this->assertEquals(1, $_SERVER['__finally.count']);
        $this->assertEquals(2, $_SERVER['__progress.count']);
        $this->assertEquals(1, $_SERVER['__then.count']);
    }

    public function testBatchFinishedEventIsDispatched()
    {
        $this->app->instance(EventDispatcher::class, $events = m::mock(EventDispatcher::class));

        $queue = m::mock(Factory::class);
        $batch = $this->createTestBatch($queue);

        $job = new class {
            use Batchable;
        };

        $queue->shouldReceive('connection')->once()
            ->with('test-connection')
            ->andReturn($connection = m::mock(QueueContract::class));

        $connection->shouldReceive('bulk')->once();

        $batch = $batch->add([$job]);

        $events->shouldReceive('dispatch')->once()->with(m::on(function ($event) use ($batch) {
            return $event instanceof BatchFinished && $event->batch === $batch;
        }));

        $batch->recordSuccessfulJob('test-id');
    }

    public function testFailedJobsCanBeRecordedWhileNotAllowingFailures()
    {
        $queue = m::mock(Factory::class);

        $batch = $this->createTestBatch($queue, $allowFailures = false);

        $job = new class {
            use Batchable;
        };

        $secondJob = new class {
            use Batchable;
        };

        $queue->shouldReceive('connection')->once()
            ->with('test-connection')
            ->andReturn($connection = m::mock(QueueContract::class));

        $connection->shouldReceive('bulk')->once();

        $batch = $batch->add([$job, $secondJob]);
        $this->assertEquals(2, $batch->pendingJobs);

        $batch->recordFailedJob('test-id', new RuntimeException('Something went wrong.'));
        $batch->recordFailedJob('test-id', new RuntimeException('Something else went wrong.'));

        $this->assertInstanceOf(Batch::class, $_SERVER['__finally.batch']);
        $this->assertFalse(isset($_SERVER['__then.batch']));

        $batch = $batch->fresh();
        $this->assertEquals(2, $batch->pendingJobs);
        $this->assertEquals(2, $batch->failedJobs);
        $this->assertTrue($batch->finished());
        $this->assertTrue($batch->cancelled());
        $this->assertEquals(1, $_SERVER['__finally.count']);
        $this->assertEquals(0, $_SERVER['__progress.count']);
        $this->assertEquals(1, $_SERVER['__catch.count']);
        $this->assertSame('Something went wrong.', $_SERVER['__catch.exception']->getMessage());
    }

    public function testFailedJobsCanBeRecordedWhileAllowingFailures()
    {
        $queue = m::mock(Factory::class);

        $batch = $this->createTestBatch($queue, $allowFailures = true);

        $job = new class {
            use Batchable;
        };

        $secondJob = new class {
            use Batchable;
        };

        $queue->shouldReceive('connection')->once()
            ->with('test-connection')
            ->andReturn($connection = m::mock(QueueContract::class));

        $connection->shouldReceive('bulk')->once();

        $batch = $batch->add([$job, $secondJob]);
        $this->assertEquals(2, $batch->pendingJobs);

        $batch->recordFailedJob('test-id', new RuntimeException('Something went wrong.'));
        $batch->recordFailedJob('test-id', new RuntimeException('Something else went wrong.'));

        // While allowing failures this batch never actually completes...
        $this->assertFalse(isset($_SERVER['__then.batch']));

        $batch = $batch->fresh();
        $this->assertEquals(2, $batch->pendingJobs);
        $this->assertEquals(2, $batch->failedJobs);
        $this->assertFalse($batch->finished());
        $this->assertFalse($batch->cancelled());
        $this->assertEquals(1, $_SERVER['__catch.count']);
        $this->assertEquals(2, $_SERVER['__progress.count']);
        $this->assertSame('Something went wrong.', $_SERVER['__catch.exception']->getMessage());
    }

    public function testPendingBatchFiltersOutFalsyJobs()
    {
        $job = new class {
            use Batchable;
        };

        $secondJob = new class {
            use Batchable;
        };

        $jobsWithNulls = collect([$job, null, $secondJob, [], 0, '', false]);

        $batch = new PendingBatch($this->app, $jobsWithNulls);

        $this->assertCount(2, $batch->jobs);
        $this->assertTrue($batch->jobs->contains($job));
        $this->assertTrue($batch->jobs->contains($secondJob));
    }

    public function testFailureCallbacksExecuteCorrectly()
    {
        $queue = m::mock(Factory::class);

        $repository = new DatabaseBatchRepository(
            new BatchFactory($queue),
            $this->app->make('db'),
            'job_batches'
        );

        $pendingBatch = (new PendingBatch($this->app, collect()))
            ->allowFailures([
                static fn (Batch $batch, $e): true => $_SERVER['__failure1.invoked'] = true,
                function (Batch $batch, $e) {
                    $_SERVER['__failure2.invoked'] = true;
                },
                function (Batch $batch, $e) {
                    $_SERVER['__failure3.batch'] = $batch;
                    $_SERVER['__failure3.exception'] = $e;
                    $_SERVER['__failure3.batch_id'] = $batch->id;
                    $_SERVER['__failure3.batch_class'] = get_class($batch);
                    $_SERVER['__failure3.exception_class'] = get_class($e);
                    $_SERVER['__failure3.exception_message'] = $e->getMessage();
                    $_SERVER['__failure3.param_count'] = func_num_args();
                },
            ])
            ->onConnection('test-connection')
            ->onQueue('test-queue');

        $batch = $repository->store($pendingBatch);

        $job = new class {
            use Batchable;
        };

        $queue->shouldReceive('connection')->once()
            ->with('test-connection')
            ->andReturn($connection = m::mock(QueueContract::class));

        $connection->shouldReceive('bulk')->once();

        $batch = $batch->add([$job]);

        $_SERVER['__failure1.invoked'] = false;
        $_SERVER['__failure2.invoked'] = false;
        $_SERVER['__failure3.batch'] = null;
        $_SERVER['__failure3.exception'] = null;

        $batch->recordFailedJob('test-id', new RuntimeException('Comprehensive callback test.'));

        $this->assertTrue($_SERVER['__failure1.invoked']);
        $this->assertTrue($_SERVER['__failure2.invoked']);
        $this->assertInstanceOf(Batch::class, $_SERVER['__failure3.batch']);
        $this->assertSame('Comprehensive callback test.', $_SERVER['__failure3.exception']->getMessage());
        $this->assertSame($batch->id, $_SERVER['__failure3.batch_id']);
        $this->assertSame(Batch::class, $_SERVER['__failure3.batch_class']);
        $this->assertSame(RuntimeException::class, $_SERVER['__failure3.exception_class']);
        $this->assertEquals(2, $_SERVER['__failure3.param_count']);
    }

    public function testBatchCanBeCancelled()
    {
        $queue = m::mock(Factory::class);

        $batch = $this->createTestBatch($queue);

        $batch->cancel();

        $batch = $batch->fresh();

        $this->assertTrue($batch->cancelled());
    }

    public function testBatchCancelledEventIsDispatched()
    {
        $this->app->instance(EventDispatcher::class, $events = m::mock(EventDispatcher::class));

        $queue = m::mock(Factory::class);
        $batch = $this->createTestBatch($queue);

        $events->shouldReceive('dispatch')->once()->with(m::on(function ($event) use ($batch) {
            return $event instanceof BatchCanceled && $event->batch->id === $batch->id;
        }));

        $batch->cancel();
    }

    public function testBatchCanBeDeleted()
    {
        $queue = m::mock(Factory::class);

        $batch = $this->createTestBatch($queue);

        $batch->delete();

        $batch = $batch->fresh();

        $this->assertNull($batch);
    }

    public function testBatchStateCanBeInspected()
    {
        $queue = m::mock(Factory::class);

        $batch = $this->createTestBatch($queue);

        $this->assertFalse($batch->finished());
        $batch->finishedAt = CarbonImmutable::now();
        $this->assertTrue($batch->finished());

        $batch->options['progress'] = [];
        $this->assertFalse($batch->hasProgressCallbacks());
        $batch->options['progress'] = [1];
        $this->assertTrue($batch->hasProgressCallbacks());

        $batch->options['then'] = [];
        $this->assertFalse($batch->hasThenCallbacks());
        $batch->options['then'] = [1];
        $this->assertTrue($batch->hasThenCallbacks());

        $this->assertFalse($batch->allowsFailures());
        $batch->options['allowFailures'] = true;
        $this->assertTrue($batch->allowsFailures());

        $this->assertFalse($batch->hasFailures());
        $batch->failedJobs = 1;
        $this->assertTrue($batch->hasFailures());

        $batch->options['catch'] = [];
        $this->assertFalse($batch->hasCatchCallbacks());
        $batch->options['catch'] = [1];
        $this->assertTrue($batch->hasCatchCallbacks());

        $this->assertFalse($batch->cancelled());
        $batch->cancelledAt = CarbonImmutable::now();
        $this->assertTrue($batch->cancelled());

        $this->assertIsString(json_encode($batch));
    }

    public function testChainCanBeAddedToBatch()
    {
        $queue = m::mock(Factory::class);

        $batch = $this->createTestBatch($queue);

        $chainHeadJob = new ChainHeadJob;

        $secondJob = new SecondTestJob;

        $thirdJob = new ThirdTestJob;

        $queue->shouldReceive('connection')->once()
            ->with('test-connection')
            ->andReturn($connection = m::mock(QueueContract::class));

        $connection->shouldReceive('bulk')->once()->with(m::on(function ($args) use ($chainHeadJob, $secondJob, $thirdJob) {
            return
                $args[0] == $chainHeadJob
                && serialize($secondJob) == $args[0]->chained[0]
                && serialize($thirdJob) == $args[0]->chained[1];
        }), '', 'test-queue');

        $batch = $batch->add([
            [$chainHeadJob, $secondJob, $thirdJob],
        ]);

        $this->assertEquals(3, $batch->totalJobs);
        $this->assertEquals(3, $batch->pendingJobs);
        $this->assertSame('test-queue', $chainHeadJob->chainQueue);
        $this->assertIsString($chainHeadJob->batchId);
        $this->assertIsString($secondJob->batchId);
        $this->assertIsString($thirdJob->batchId);
        $this->assertInstanceOf(CarbonImmutable::class, $batch->createdAt);
    }

    public function testChainedClosureAfterMultipleBatchesIsProperlyDispatched()
    {
        Queue::fake();

        Bus::chain([
            Bus::batch([new TestBatchJob])->name('Batch 1'),
            Bus::batch([new TestBatchJob])->name('Batch 2'),
            function () {
            },
        ])->dispatch();

        $this->assertTrue(true);
    }

    public function testOptionsSerializationOnPostgres()
    {
        $pendingBatch = (new PendingBatch($this->app, Collection::make()))
            ->onQueue('test-queue');

        $connection = m::spy(PostgresConnection::class);
        $resolver = m::mock(ConnectionResolverInterface::class);
        $resolver->shouldReceive('connection')->andReturn($connection);
        $builder = m::spy(Builder::class);

        $connection->shouldReceive('table')->andReturn($builder);
        $builder->shouldReceive('useWritePdo')->andReturnSelf();
        $builder->shouldReceive('where')->andReturnSelf();
        $builder->shouldReceive('first')->andReturn((object) [
            'id' => 'test-id',
            'name' => '',
            'total_jobs' => 0,
            'pending_jobs' => 0,
            'failed_jobs' => 0,
            'failed_job_ids' => '[]',
            'options' => base64_encode(serialize($pendingBatch->options)),
            'created_at' => time(),
            'cancelled_at' => null,
            'finished_at' => null,
        ]);

        $repository = new DatabaseBatchRepository(
            new BatchFactory(m::mock(Factory::class)),
            $resolver,
            'job_batches'
        );

        $repository->store($pendingBatch);

        $builder->shouldHaveReceived('insert')
            ->withArgs(function ($argument) use ($pendingBatch) {
                return unserialize(base64_decode($argument['options'])) === $pendingBatch->options;
            });
    }

    #[DataProvider('serializedOptions')]
    public function testOptionsUnserializeOnPostgres($serialize, $options)
    {
        $factory = m::mock(BatchFactory::class);

        $connection = m::spy(PostgresConnection::class);
        $resolver = m::mock(ConnectionResolverInterface::class);
        $resolver->shouldReceive('connection')->andReturn($connection);

        $connection->shouldReceive('table->useWritePdo->where->first')
            ->andReturn($m = (object) [
                'id' => '',
                'name' => '',
                'total_jobs' => '',
                'pending_jobs' => '',
                'failed_jobs' => '',
                'failed_job_ids' => '[]',
                'options' => $serialize,
                'created_at' => now()->timestamp,
                'cancelled_at' => null,
                'finished_at' => null,
            ]);

        $batch = new DatabaseBatchRepository($factory, $resolver, 'job_batches');

        $factory->shouldReceive('make')
            ->withSomeOfArgs($batch, '', '', '', '', '', '', $options)
            ->andReturn(m::mock(Batch::class));

        $batch->find(1);
    }

    public static function serializedOptions(): array
    {
        $options = [1, 2];

        return [
            [serialize($options), $options],
            [base64_encode(serialize($options)), $options],
        ];
    }

    protected function createTestBatch($queue, $allowFailures = false)
    {
        $repository = new DatabaseBatchRepository(
            new BatchFactory($queue),
            $this->app->make('db'),
            'job_batches'
        );

        $pendingBatch = (new PendingBatch($this->app, Collection::make()))
            ->progress(function (Batch $batch) {
                $_SERVER['__progress.batch'] = $batch;
                ++$_SERVER['__progress.count'];
            })
            ->then(function (Batch $batch) {
                $_SERVER['__then.batch'] = $batch;
                ++$_SERVER['__then.count'];
            })
            ->catch(function (Batch $batch, $e) {
                $_SERVER['__catch.batch'] = $batch;
                $_SERVER['__catch.exception'] = $e;
                ++$_SERVER['__catch.count'];
            })
            ->finally(function (Batch $batch) {
                $_SERVER['__finally.batch'] = $batch;
                ++$_SERVER['__finally.count'];
            })
            ->allowFailures($allowFailures)
            ->onConnection('test-connection')
            ->onQueue('test-queue');

        return $repository->store($pendingBatch);
    }
}

class TestBatchJob implements ShouldQueue
{
    use Batchable;
    use Dispatchable;
    use Queueable;

    public function handle()
    {
    }
}

class ChainHeadJob implements ShouldQueue
{
    use Batchable;
    use Dispatchable;
    use Queueable;
}

class SecondTestJob implements ShouldQueue
{
    use Batchable;
    use Dispatchable;
    use Queueable;
}

class ThirdTestJob implements ShouldQueue
{
    use Batchable;
    use Dispatchable;
    use Queueable;
}
