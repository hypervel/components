<?php

declare(strict_types=1);

namespace Hypervel\Tests\Bus;

use Generator;
use Hypervel\Bus\Batch;
use Hypervel\Bus\Batchable;
use Hypervel\Bus\BatchFactory;
use Hypervel\Bus\DatabaseBatchRepository;
use Hypervel\Bus\Events\BatchCanceled;
use Hypervel\Bus\Events\BatchFinished;
use Hypervel\Bus\Events\BatchStarted;
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
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Collection;
use Hypervel\Support\Facades\Bus;
use Hypervel\Support\Facades\Queue;
use Hypervel\Testbench\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Throwable;

class BusBatchTest extends TestCase
{
    use RefreshDatabase;

    protected bool $migrateRefresh = true;

    /**
     * Get the migration options for the batch repository.
     */
    protected function migrateFreshUsing(): array
    {
        return [
            '--seed' => $this->shouldSeed(),
            '--database' => $this->getRefreshConnection(),
            '--realpath' => true,
            '--path' => __DIR__ . '/Fixtures/migrations',
        ];
    }

    /**
     * Set up the test environment.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $_SERVER['__finally.count'] = 0;
        $_SERVER['__progress.count'] = 0;
        $_SERVER['__then.count'] = 0;
        $_SERVER['__catch.count'] = 0;
    }

    /**
     * Clean up the callback state.
     */
    protected function tearDown(): void
    {
        unset(
            $_SERVER['__finally.count'],
            $_SERVER['__progress.count'],
            $_SERVER['__then.count'],
            $_SERVER['__catch.count'],
            $_SERVER['__finally.batch'],
            $_SERVER['__progress.batch'],
            $_SERVER['__then.batch'],
            $_SERVER['__catch.batch'],
            $_SERVER['__catch.exception'],
            $_SERVER['__failure1.invoked'],
            $_SERVER['__failure2.invoked'],
            $_SERVER['__failure3.batch'],
            $_SERVER['__failure3.exception'],
            $_SERVER['__failure3.batch_id'],
            $_SERVER['__failure3.batch_class'],
            $_SERVER['__failure3.exception_class'],
            $_SERVER['__failure3.exception_message'],
            $_SERVER['__failure3.param_count'],
        );

        parent::tearDown();
    }

    public function testBatchRepositoryUsesDefaultDatabaseConnectionWhenBatchingDatabaseIsNull(): void
    {
        $this->app->make('config')->set('queue.batching.database', null);
        $this->app->forgetInstance(DatabaseBatchRepository::class);

        $repository = $this->app->make(DatabaseBatchRepository::class);

        $this->assertSame($this->app->make('db')->connection(), $repository->getConnection());
    }

    public function testBatchRepositoryAppliesAZeroBeforeCursor(): void
    {
        $queue = m::mock(Factory::class);
        $batch = $this->createTestBatch($queue);

        $repository = new DatabaseBatchRepository(
            new BatchFactory($queue),
            $this->app->make('db'),
            'job_batches'
        );

        $this->assertSame($batch->id, $repository->get(50, null)[0]->id);
        $this->assertSame([], $repository->get(50, '0'));
    }

    public function testJobsCanBeAddedToTheBatch(): void
    {
        $queue = m::mock(Factory::class);

        $batch = $this->createTestBatch($queue);

        $job = new class {
            use Batchable;
        };

        $secondJob = new class {
            use Batchable;
        };

        $thirdJob = function (): void {
        };

        $connection = m::mock(QueueContract::class);
        $queue->expects('connection')
            ->with('test-connection')
            ->andReturn($connection);

        $connection->expects('bulk')->with(m::on(function (array $jobs) use ($job, $secondJob, $thirdJob): bool {
            return
                count($jobs) === 3
                && $jobs[0] === $job
                && $jobs[1] === $secondJob
                && $jobs[2] instanceof CallQueuedClosure
                && $jobs[2]->closure->getClosure() === $thirdJob
                && is_string($jobs[2]->batchId);
        }), '', 'test-queue');

        $batch = $batch->add([$job, $secondJob, $thirdJob]);

        $this->assertEquals(3, $batch->totalJobs);
        $this->assertEquals(3, $batch->pendingJobs);
        $this->assertIsString($job->batchId);
        $this->assertSame(CarbonImmutable::class, $batch->createdAt::class);
    }

    public function testJobsCanBeAddedToPendingBatch(): void
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

    public function testJobsCanBeAddedToThePendingBatchFromIterable(): void
    {
        $batch = new PendingBatch($this->app, collect());
        $this->assertCount(0, $batch->jobs);

        $count = 3;
        $generator = function (int $jobsCount): Generator {
            for ($i = 0; $i < $jobsCount; ++$i) {
                yield new class {
                    use Batchable;
                };
            }
        };

        $batch->add($generator($count));
        $this->assertCount($count, $batch->jobs);
    }

    public function testProcessedJobsCanBeCalculated(): void
    {
        $queue = m::mock(Factory::class);

        $batch = $this->createTestBatch($queue);

        $batch->totalJobs = 10;
        $batch->pendingJobs = 4;

        $this->assertEquals(6, $batch->processedJobs());
        $this->assertEquals(60, $batch->progress());
    }

    public function testSuccessfulJobsCanBeRecorded(): void
    {
        $queue = m::mock(Factory::class);

        $batch = $this->createTestBatch($queue);

        $job = new class {
            use Batchable;
        };

        $secondJob = new class {
            use Batchable;
        };

        $connection = m::mock(QueueContract::class);
        $queue->expects('connection')
            ->with('test-connection')
            ->andReturn($connection);

        $connection->expects('bulk');

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

    public function testBatchFinishedEventIsDispatched(): void
    {
        $events = m::mock(EventDispatcher::class);
        $this->app->instance(EventDispatcher::class, $events);

        $queue = m::mock(Factory::class);
        $batch = $this->createTestBatch($queue);

        $job = new class {
            use Batchable;
        };

        $connection = m::mock(QueueContract::class);
        $queue->expects('connection')
            ->with('test-connection')
            ->andReturn($connection);

        $connection->expects('bulk');

        $batch = $batch->add([$job]);

        $events->expects('hasListeners')->with(BatchStarted::class)->andReturnTrue();

        $events->expects('dispatch')->with(m::on(function (object $event) use ($batch): bool {
            return $event instanceof BatchStarted && $event->batch === $batch;
        }));

        $events->expects('hasListeners')->with(BatchFinished::class)->andReturnTrue();

        $events->expects('dispatch')->with(m::on(function (object $event) use ($batch): bool {
            return $event instanceof BatchFinished && $event->batch === $batch;
        }));

        $batch->recordSuccessfulJob('test-id');
    }

    public function testBatchStartedEventIsDispatchedOnceWhenTheFirstJobSucceeds(): void
    {
        $events = m::mock(EventDispatcher::class);
        $this->app->instance(EventDispatcher::class, $events);

        $queue = m::mock(Factory::class);
        $batch = $this->createTestBatch($queue);

        $firstJob = new class {
            use Batchable;
        };

        $secondJob = new class {
            use Batchable;
        };

        $connection = m::mock(QueueContract::class);
        $queue->expects('connection')
            ->with('test-connection')
            ->andReturn($connection);

        $connection->expects('bulk');

        $batch = $batch->add([$firstJob, $secondJob]);

        $events->expects('hasListeners')->with(BatchStarted::class)->andReturnTrue();
        $events->expects('dispatch')->with(m::on(function (object $event) use ($batch): bool {
            return $event instanceof BatchStarted && $event->batch === $batch;
        }));
        $events->expects('hasListeners')->with(BatchFinished::class)->andReturnTrue();
        $events->expects('dispatch')->with(m::type(BatchFinished::class));

        $batch->recordSuccessfulJob('test-id-1');
        $batch->recordSuccessfulJob('test-id-2');
    }

    public function testBatchStartedEventIsDispatchedOnceWhenTheFirstJobFails(): void
    {
        $events = m::mock(EventDispatcher::class);
        $this->app->instance(EventDispatcher::class, $events);

        $queue = m::mock(Factory::class);
        $batch = $this->createTestBatch($queue, $allowFailures = true);

        $firstJob = new class {
            use Batchable;
        };

        $secondJob = new class {
            use Batchable;
        };

        $connection = m::mock(QueueContract::class);
        $queue->expects('connection')
            ->with('test-connection')
            ->andReturn($connection);

        $connection->expects('bulk');

        $batch = $batch->add([$firstJob, $secondJob]);

        $events->expects('hasListeners')->with(BatchStarted::class)->andReturnTrue();
        $events->expects('dispatch')->with(m::on(function (object $event) use ($batch): bool {
            return $event instanceof BatchStarted && $event->batch === $batch;
        }));

        $batch->recordFailedJob('test-id-1', new RuntimeException('Something went wrong.'));
        $batch->recordFailedJob('test-id-2', new RuntimeException('Something else went wrong.'));
    }

    public function testFailedJobsCanBeRecordedWhileNotAllowingFailures(): void
    {
        $queue = m::mock(Factory::class);

        $batch = $this->createTestBatch($queue, $allowFailures = false);

        $job = new class {
            use Batchable;
        };

        $secondJob = new class {
            use Batchable;
        };

        $connection = m::mock(QueueContract::class);
        $queue->expects('connection')
            ->with('test-connection')
            ->andReturn($connection);

        $connection->expects('bulk');

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

    public function testFailedJobsCanBeRecordedWhileAllowingFailures(): void
    {
        $queue = m::mock(Factory::class);

        $batch = $this->createTestBatch($queue, $allowFailures = true);

        $job = new class {
            use Batchable;
        };

        $secondJob = new class {
            use Batchable;
        };

        $connection = m::mock(QueueContract::class);
        $queue->expects('connection')
            ->with('test-connection')
            ->andReturn($connection);

        $connection->expects('bulk');

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

    public function testPendingBatchFiltersOutFalsyJobs(): void
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

    public function testFailureCallbacksExecuteCorrectly(): void
    {
        $queue = m::mock(Factory::class);

        $repository = new DatabaseBatchRepository(
            new BatchFactory($queue),
            $this->app->make('db'),
            'job_batches'
        );

        $pendingBatch = (new PendingBatch($this->app, collect()))
            ->allowFailures([
                static fn (Batch $batch, ?Throwable $e): true => $_SERVER['__failure1.invoked'] = true,
                function (Batch $batch, ?Throwable $e): void {
                    $_SERVER['__failure2.invoked'] = true;
                },
                function (Batch $batch, ?Throwable $e): void {
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

        $connection = m::mock(QueueContract::class);
        $queue->expects('connection')
            ->with('test-connection')
            ->andReturn($connection);

        $connection->expects('bulk');

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

    public function testBatchCanBeCancelled(): void
    {
        $queue = m::mock(Factory::class);

        $batch = $this->createTestBatch($queue);

        $batch->cancel();

        $batch = $batch->fresh();

        $this->assertTrue($batch->cancelled());
    }

    public function testBatchCancelledEventIsDispatched(): void
    {
        $events = m::mock(EventDispatcher::class);
        $this->app->instance(EventDispatcher::class, $events);

        $queue = m::mock(Factory::class);
        $batch = $this->createTestBatch($queue);

        $exception = new RuntimeException('Something went wrong.');

        $events->expects('hasListeners')->with(BatchCanceled::class)->andReturnTrue();
        $events->expects('dispatch')->with(m::on(function (object $event) use ($batch, $exception): bool {
            return $event instanceof BatchCanceled
                && $event->batch->id === $batch->id
                && $event->exception === $exception;
        }));

        $batch->cancel($exception);
    }

    public function testBatchCanBeDeleted(): void
    {
        $queue = m::mock(Factory::class);

        $batch = $this->createTestBatch($queue);

        $batch->delete();

        $batch = $batch->fresh();

        $this->assertNull($batch);
    }

    public function testDeletedBatchIgnoresLateJobResultsAndCallbacks(): void
    {
        $queue = m::mock(Factory::class);
        $batch = $this->createTestBatch($queue, $allowFailures = true);

        $job = new class {
            use Batchable;
        };

        $connection = m::mock(QueueContract::class);
        $queue->expects('connection')
            ->with('test-connection')
            ->andReturn($connection);

        $connection->expects('bulk');

        $batch = $batch->add([$job]);
        $batch->delete();

        $batch->recordSuccessfulJob('successful-job');
        $batch->recordFailedJob('failed-job', new RuntimeException('Something went wrong.'));

        $this->assertNull($batch->fresh());
        $this->assertSame(0, $_SERVER['__finally.count']);
        $this->assertSame(0, $_SERVER['__progress.count']);
        $this->assertSame(0, $_SERVER['__then.count']);
        $this->assertSame(0, $_SERVER['__catch.count']);
    }

    public function testBatchStateCanBeInspected(): void
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

    public function testChainCanBeAddedToBatch(): void
    {
        $queue = m::mock(Factory::class);

        $batch = $this->createTestBatch($queue);

        $chainHeadJob = new ChainHeadJob;

        $secondJob = new SecondTestJob;

        $thirdJob = new ThirdTestJob;

        $connection = m::mock(QueueContract::class);
        $queue->expects('connection')
            ->with('test-connection')
            ->andReturn($connection);

        $connection->expects('bulk')->with(m::on(function (array $jobs) use ($chainHeadJob, $secondJob, $thirdJob): bool {
            return
                $jobs[0] === $chainHeadJob
                && serialize($secondJob) === $jobs[0]->chained[0]
                && serialize($thirdJob) === $jobs[0]->chained[1];
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
        $this->assertSame(CarbonImmutable::class, $batch->createdAt::class);
    }

    public function testChainedJobsPreserveTheirRoutesWhenTheBatchHasNone(): void
    {
        $queue = m::mock(Factory::class);

        $repository = new DatabaseBatchRepository(
            new BatchFactory($queue),
            $this->app->make('db'),
            'job_batches'
        );

        $batch = $repository->store(new PendingBatch($this->app, collect()));

        $firstJob = (new ChainHeadJob)
            ->onConnection('custom-connection')
            ->onQueue('custom-queue');
        $secondJob = (new SecondTestJob)
            ->onConnection('custom-connection')
            ->onQueue('custom-queue');

        $connection = m::mock(QueueContract::class);
        $queue->expects('connection')
            ->with(null)
            ->andReturn($connection);

        $connection->expects('bulk')->with(m::type('array'), '', null);

        $batch->add([
            [$firstJob, $secondJob],
        ]);

        $this->assertSame('custom-connection', $firstJob->connection);
        $this->assertSame('custom-connection', $secondJob->connection);
        $this->assertSame('custom-queue', $firstJob->queue);
        $this->assertSame('custom-queue', $secondJob->queue);
    }

    public function testChainedClosureAfterMultipleBatchesIsProperlyDispatched(): void
    {
        Queue::fake();

        Bus::chain([
            Bus::batch([new TestBatchJob])->name('Batch 1'),
            Bus::batch([new TestBatchJob])->name('Batch 2'),
            function (): void {
            },
        ])->dispatch();

        $this->assertTrue(true);
    }

    public function testOptionsSerializationOnPostgres(): void
    {
        $pendingBatch = (new PendingBatch($this->app, Collection::make()))
            ->onQueue('test-queue');

        $connection = m::spy(PostgresConnection::class);
        $resolver = m::mock(ConnectionResolverInterface::class);
        $resolver->shouldReceive('connection')->andReturn($connection);
        $builder = m::spy(Builder::class);

        $connection->expects('table')->times(2)->andReturn($builder);
        $builder->expects('useWritePdo')->andReturnSelf();
        $builder->expects('where')->andReturnSelf();
        $builder->expects('first')->andReturn((object) [
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
            ->withArgs(function (array $argument) use ($pendingBatch): bool {
                return unserialize(base64_decode($argument['options'])) === $pendingBatch->options;
            });
    }

    #[DataProvider('serializedOptions')]
    public function testOptionsUnserializeOnPostgres(string $serialize, array $options): void
    {
        $factory = m::mock(BatchFactory::class);

        $connection = m::spy(PostgresConnection::class);
        $resolver = m::mock(ConnectionResolverInterface::class);
        $resolver->shouldReceive('connection')->andReturn($connection);

        $connection->expects('table->useWritePdo->where->first')
            ->andReturn((object) [
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

        $factory->expects('make')
            ->withSomeOfArgs($batch, '', '', 0, 0, 0, [], $options)
            ->andReturn(m::mock(Batch::class));

        $batch->find('1');
    }

    /**
     * Provide supported serialized batch options.
     */
    public static function serializedOptions(): array
    {
        $options = [1, 2];

        return [
            [serialize($options), $options],
            [base64_encode(serialize($options)), $options],
        ];
    }

    /**
     * Store a batch with callbacks that record their invocation.
     */
    protected function createTestBatch(Factory $queue, bool $allowFailures = false): Batch
    {
        $repository = new DatabaseBatchRepository(
            new BatchFactory($queue),
            $this->app->make('db'),
            'job_batches'
        );

        $pendingBatch = (new PendingBatch($this->app, Collection::make()))
            ->progress(function (Batch $batch): void {
                $_SERVER['__progress.batch'] = $batch;
                ++$_SERVER['__progress.count'];
            })
            ->then(function (Batch $batch): void {
                $_SERVER['__then.batch'] = $batch;
                ++$_SERVER['__then.count'];
            })
            ->catch(function (Batch $batch, ?Throwable $e): void {
                $_SERVER['__catch.batch'] = $batch;
                $_SERVER['__catch.exception'] = $e;
                ++$_SERVER['__catch.count'];
            })
            ->finally(function (Batch $batch): void {
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

    /**
     * Handle the job.
     */
    public function handle(): void
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
