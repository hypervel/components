<?php

declare(strict_types=1);

namespace Hypervel\Tests\Bus;

use Generator;
use Hypervel\Bus\Batch;
use Hypervel\Bus\Batchable;
use Hypervel\Bus\BatchFactory;
use Hypervel\Bus\BatchRepository;
use Hypervel\Bus\ChainedBatch;
use Hypervel\Bus\DatabaseBatchRepository;
use Hypervel\Bus\Dispatcher;
use Hypervel\Bus\Events\BatchCanceled;
use Hypervel\Bus\Events\BatchFinished;
use Hypervel\Bus\Events\BatchStarted;
use Hypervel\Bus\PendingBatch;
use Hypervel\Bus\Queueable;
use Hypervel\Bus\UpdatedBatchJobCounts;
use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Contracts\Events\Dispatcher as EventDispatcher;
use Hypervel\Contracts\Queue\Factory;
use Hypervel\Contracts\Queue\Job;
use Hypervel\Contracts\Queue\Queue as QueueContract;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Database\ConnectionResolverInterface;
use Hypervel\Database\PostgresConnection;
use Hypervel\Database\Query\Builder;
use Hypervel\Events\Dispatcher as EventsDispatcher;
use Hypervel\Foundation\Bus\Dispatchable;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Queue\CallQueuedClosure;
use Hypervel\Queue\CallQueuedHandler;
use Hypervel\Queue\InteractsWithQueue;
use Hypervel\Queue\Jobs\FakeJob;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Collection;
use Hypervel\Support\Facades\Bus;
use Hypervel\Support\Facades\Queue;
use Hypervel\Support\Testing\Fakes\EventFake;
use Hypervel\Support\Testing\Fakes\QueueFake;
use Hypervel\Testbench\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use JMac\Testing\Matching\Argument;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Swoole\Coroutine\CanceledException;
use Throwable;

class BusBatchTest extends TestCase
{
    use RefreshDatabase;
    use VerifiesDoubles;

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

        RetriedBatchJob::$handled = false;
        RetriedBatchableOnlyJob::$handled = false;
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
        $queue = Double::for(Factory::class)->strict();
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
        $queue = Double::for(Factory::class)->strict();

        $batch = $this->createTestBatch($queue);

        $job = new class {
            use Batchable;
        };

        $secondJob = new class {
            use Batchable;
        };

        $thirdJob = function (): void {
        };
        $job->batchPositionId = 'previous-enrollment';

        $connection = Double::for(QueueContract::class)->strict();
        $queue->expects('connection')
            ->with('test-connection')
            ->returns($connection);

        $connection->expects('bulk')->with(Argument::satisfies(function (array $jobs) use ($job, $secondJob, $thirdJob): bool {
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
        $this->assertNull($job->batchPositionId);
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

    public function testStoredOutboxDefaultsApplyToNewJobsWithoutReplacingExplicitChoices(): void
    {
        Queue::fake();
        $database = $this->app->make(DatabaseBatchRepository::class)->getConnection()->getName();

        $defaultJob = new class {
            use Batchable;
        };
        $directJob = (new TestBatchJob)->onOutboxDatabase('previous')->withoutOutbox();
        $aliasOwnerJob = (new TestBatchJob)->onOutboxDatabase($database . '::write');
        $propertyOptOutJob = (new TestBatchJob)->onOutboxDatabase('unused');
        $propertyOptOutJob->viaOutbox = false;
        $nullJob = new TestBatchJob;
        $nullJob->viaOutbox = null;
        $nullJob->outboxDatabase = null;

        $batch = Bus::batch([$defaultJob, $directJob, $aliasOwnerJob, $propertyOptOutJob, $nullJob])
            ->onOutboxDatabase($database)
            ->dispatch();

        $this->assertTrue($defaultJob->viaOutbox);
        $this->assertSame($database, $defaultJob->outboxDatabase);
        $this->assertFalse($directJob->viaOutbox);
        $this->assertNull($directJob->outboxDatabase);
        $this->assertSame($database . '::write', $aliasOwnerJob->outboxDatabase);
        $this->assertFalse($propertyOptOutJob->viaOutbox);
        $this->assertSame('unused', $propertyOptOutJob->outboxDatabase);
        $this->assertNull($nullJob->viaOutbox);
        $this->assertNull($nullJob->outboxDatabase);

        $added = new TestBatchJob;
        $batch->fresh()->add([$added]);

        $this->assertTrue($added->viaOutbox);
        $this->assertSame($database, $added->outboxDatabase);
        $this->assertSame(6, $batch->fresh()->totalJobs);
    }

    public function testBatchOutboxDefaultsDoNotPropagateToLaterChainJobs(): void
    {
        Queue::fake();

        $first = new TestBatchJob;
        $second = new TestBatchJob;

        Bus::batch([[$first, $second]])->viaOutbox()->dispatch();

        $this->assertTrue($first->viaOutbox);
        $this->assertArrayNotHasKey('viaOutbox', get_object_vars(unserialize($first->chained[0])));
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
        $queue = new QueueFake($this->app);

        $batch = $this->createTestBatch($queue);

        $batch->totalJobs = 10;
        $batch->pendingJobs = 4;

        $this->assertEquals(6, $batch->processedJobs());
        $this->assertEquals(60, $batch->progress());
    }

    #[TestWith([false], 'ordinary callback failure')]
    #[TestWith([true], 'wrapped cancellation')]
    public function testProgressContinuesOnlyAfterOrdinaryCallbackFailures(bool $canceled): void
    {
        $failure = new RuntimeException('Progress failed.', previous: $canceled ? new CanceledException : null);
        $handler = Double::for(ExceptionHandler::class)->strict();
        $handler->expects('report')->times($canceled ? 0 : 1)->with(Argument::same($failure), []);
        $this->app->instance(ExceptionHandler::class, $handler);
        $repository = Double::for(BatchRepository::class)->strict();
        $continued = false;
        $batch = new Batch(Double::for(Factory::class)->strict(), $repository, 'batch', 'batch', 2, 2, 0, [], [
            'progress' => [
                static fn () => throw $failure,
                static function () use (&$continued): void { $continued = true; },
            ],
        ], CarbonImmutable::now());
        $repository->allows('find')->with('batch')->returns($batch);
        $repository->expects('decrementPendingJobs')->returns(new UpdatedBatchJobCounts(1));
        $caught = null;

        try {
            $batch->recordSuccessfulJob('job');
        } catch (Throwable $exception) {
            $caught = $exception;
        }

        $this->assertSame($canceled ? $failure : null, $caught);
        $this->assertSame(! $canceled, $continued);
    }

    public function testSuccessfulJobsCanBeRecorded(): void
    {
        $queue = Double::for(Factory::class)->strict();

        $batch = $this->createTestBatch($queue);

        $job = new class {
            use Batchable;
        };

        $secondJob = new class {
            use Batchable;
        };

        $connection = Double::for(QueueContract::class)->strict();
        $queue->expects('connection')
            ->with('test-connection')
            ->returns($connection);

        $connection->expects('bulk');

        $batch = $batch->add([$job, $secondJob]);
        $this->assertEquals(2, $batch->pendingJobs);

        $batch->recordSuccessfulJob('test-id');
        $batch->recordSuccessfulJob('test-id');
        $batch->recordFailedJob('test-id', new RuntimeException);

        $this->assertSame(1, $batch->fresh()->pendingJobs);
        $this->assertFalse($batch->fresh()->finished());
        $this->assertSame(0, $batch->fresh()->failedJobs);
        $this->assertSame(1, $_SERVER['__progress.count']);
        $this->assertSame(0, $_SERVER['__finally.count']);
        $this->assertSame(0, $_SERVER['__then.count']);
        $batch->recordSuccessfulJob('second-id');

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
        $events = new EventFake(new EventsDispatcher);
        $this->app->instance(EventDispatcher::class, $events);

        $queue = new QueueFake($this->app);
        $batch = $this->createTestBatch($queue);

        $job = new class {
            use Batchable;
        };

        $batch = $batch->add([$job]);

        $batch->recordSuccessfulJob('test-id');

        $events->assertDispatchedOnce(BatchStarted::class);
        $events->assertDispatched(BatchStarted::class, function (BatchStarted $event) use ($batch): bool {
            return $event->batch === $batch;
        });
        $events->assertDispatchedOnce(BatchFinished::class);
        $events->assertDispatched(BatchFinished::class, function (BatchFinished $event) use ($batch): bool {
            return $event->batch->id === $batch->id
                && $event->batch->finished()
                && $event->batch->pendingJobs === 0;
        });
    }

    public function testBatchStartedEventIsDispatched(): void
    {
        $events = new EventFake(new EventsDispatcher);
        $this->app->instance(EventDispatcher::class, $events);

        $queue = new QueueFake($this->app);
        $batch = $this->createTestBatch($queue);

        $job = new class {
            use Batchable;
        };

        $secondJob = new class {
            use Batchable;
        };

        $batch = $batch->add([$job, $secondJob]);

        $batch->recordSuccessfulJob('test-id-1');
        $batch->recordSuccessfulJob('test-id-2');

        $events->assertDispatchedOnce(BatchStarted::class);
        $events->assertDispatched(BatchStarted::class, function (BatchStarted $event) use ($batch): bool {
            return $event->batch === $batch;
        });
        $events->assertDispatchedOnce(BatchFinished::class);
    }

    public function testBatchStartedEventIsDispatchedWhenFirstJobFails(): void
    {
        $events = new EventFake(new EventsDispatcher);
        $this->app->instance(EventDispatcher::class, $events);

        $queue = new QueueFake($this->app);
        $batch = $this->createTestBatch($queue, $allowFailures = true);

        $job = new class {
            use Batchable;
        };

        $secondJob = new class {
            use Batchable;
        };

        $batch = $batch->add([$job, $secondJob]);

        $batch->recordFailedJob('test-id-1', new RuntimeException('Something went wrong.'));
        $batch->recordFailedJob('test-id-2', new RuntimeException('Something else went wrong.'));

        $events->assertDispatchedOnce(BatchStarted::class);
        $events->assertDispatched(BatchStarted::class, function (BatchStarted $event) use ($batch): bool {
            return $event->batch === $batch;
        });
    }

    public function testFailedJobsCanBeRecordedWhileNotAllowingFailures(): void
    {
        $queue = Double::for(Factory::class)->strict();

        $batch = $this->createTestBatch($queue, $allowFailures = false);

        $job = new class {
            use Batchable;
        };

        $secondJob = new class {
            use Batchable;
        };

        $connection = Double::for(QueueContract::class)->strict();
        $queue->expects('connection')
            ->with('test-connection')
            ->returns($connection);

        $connection->expects('bulk');

        $batch = $batch->add([$job, $secondJob]);
        $this->assertEquals(2, $batch->pendingJobs);

        // Distinct IDs: Laravel records 'test-id' twice, which is one job failing twice, not every job running.
        $batch->recordFailedJob('first-id', new RuntimeException('Something went wrong.'));
        $batch->recordFailedJob('second-id', new RuntimeException('Something else went wrong.'));

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
        $queue = Double::for(Factory::class)->strict();

        $batch = $this->createTestBatch($queue, $allowFailures = true);

        $job = new class {
            use Batchable;
        };

        $secondJob = new class {
            use Batchable;
        };

        $connection = Double::for(QueueContract::class)->strict();
        $queue->expects('connection')
            ->with('test-connection')
            ->returns($connection);

        $connection->expects('bulk');

        $batch = $batch->add([$job, $secondJob]);
        $this->assertEquals(2, $batch->pendingJobs);

        // Distinct IDs: Laravel records 'test-id' twice, which is one job failing twice, not every job running.
        $batch->recordFailedJob('first-id', new RuntimeException('Something went wrong.'));
        $batch->recordFailedJob('second-id', new RuntimeException('Something else went wrong.'));

        $this->assertFalse(isset($_SERVER['__then.batch']));

        $batch = $batch->fresh();
        $this->assertEquals(2, $batch->pendingJobs);
        $this->assertEquals(2, $batch->failedJobs);
        $this->assertTrue($batch->finished());
        $this->assertTrue($_SERVER['__finally.batch']->finished());
        $this->assertFalse($batch->cancelled());
        $this->assertEquals(1, $_SERVER['__catch.count']);
        $this->assertEquals(2, $_SERVER['__progress.count']);
        $this->assertSame('Something went wrong.', $_SERVER['__catch.exception']->getMessage());
    }

    public function testBatchAllowingFailuresIsFinishedWhenTheLastJobSucceedsAfterAFailure(): void
    {
        $queue = Double::for(Factory::class)->strict();

        $batch = $this->createTestBatch($queue, $allowFailures = true);

        $connection = Double::for(QueueContract::class)->strict();
        $queue->expects('connection')->with('test-connection')->returns($connection);

        $connection->expects('bulk');

        $batch = $batch->add([new class {
            use Batchable;
        }, new class {
            use Batchable;
        }]);

        $batch->recordFailedJob('first-id', new RuntimeException('Something went wrong.'));

        $this->assertFalse($batch->fresh()->finished());

        $batch->recordSuccessfulJob('second-id');

        $batch = $batch->fresh();
        $this->assertTrue($batch->finished());
        $this->assertEquals(1, $batch->pendingJobs);
        $this->assertSame(['first-id'], $batch->failedJobIds);
        $this->assertEquals(0, $_SERVER['__then.count']);
        $this->assertEquals(1, $_SERVER['__finally.count']);
        $this->assertTrue($_SERVER['__finally.batch']->finished());

        $batch->recordSuccessfulJob('first-id');

        $this->assertSame([], $batch->fresh()->failedJobIds);
        $this->assertEquals(1, $_SERVER['__then.count']);
    }

    public function testRetriedJobsDoNotCompleteTheBatchUntilEveryJobHasRun(): void
    {
        $queue = Double::for(Factory::class)->strict();

        $batch = $this->createTestBatch($queue, $allowFailures = true);

        $connection = Double::for(QueueContract::class)->strict();
        $queue->expects('connection')->with('test-connection')->returns($connection);

        $connection->expects('bulk');

        $batch = $batch->add([new class {
            use Batchable;
        }, new class {
            use Batchable;
        }]);

        $batch->recordFailedJob('first-id', new RuntimeException('Something went wrong.'));
        $batch->recordFailedJob('first-id', new RuntimeException('The retry failed too.'));
        $batch->recordSuccessfulJob('first-id');

        $this->assertFalse($batch->fresh()->finished());
        $this->assertEquals(0, $_SERVER['__finally.count']);

        $batch->recordSuccessfulJob('second-id');

        $this->assertTrue($batch->fresh()->finished());
        $this->assertEquals(1, $_SERVER['__then.count']);
        $this->assertEquals(1, $_SERVER['__finally.count']);
    }

    /**
     * @param class-string<RetriedBatchableOnlyJob|RetriedBatchJob> $job
     */
    #[TestWith([RetriedBatchJob::class])]
    #[TestWith([RetriedBatchableOnlyJob::class])]
    public function testRetriedJobDoesItsWorkAfterItsBatchFinishedWithFailures(string $job): void
    {
        $queue = Double::for(Factory::class)->strict();

        $batch = $this->createTestBatch($queue, $allowFailures = true);

        $connection = Double::for(QueueContract::class)->strict();
        $queue->expects('connection')->with('test-connection')->returns($connection);

        $connection->expects('bulk');

        $batch = $batch->add([new $job, new $job]);

        $batch->recordFailedJob('first-id', new RuntimeException('Something went wrong.'));
        $batch->recordSuccessfulJob('second-id');

        $this->assertTrue($batch->fresh()->finished());

        $handler = new CallQueuedHandler(new Dispatcher($this->app), $this->app);
        $command = serialize((new $job)->withBatchId($batch->id));

        // Horizon gives each retry a new UUID and carries the original batch job ID.
        $handler->failed(['command' => $command], new RuntimeException('The retry failed.'), 'first-retry-uuid', new FakeJob([
            'uuid' => 'first-retry-uuid',
            'batchJobId' => 'first-id',
        ]));

        $this->assertSame(['first-id'], $batch->fresh()->failedJobIds);
        $this->assertEquals(1, $_SERVER['__finally.count']);

        $handler->call(new FakeJob(['uuid' => 'second-retry-uuid', 'batchJobId' => 'first-id']), ['command' => $command]);

        $this->assertTrue($job::$handled);
        $this->assertSame([], $batch->fresh()->failedJobIds);
        $this->assertEquals(1, $_SERVER['__then.count']);
    }

    public function testWorkAddedWhileOnlyFailuresRemainReopensTheBatch(): void
    {
        $queue = Double::for(Factory::class)->strict();
        $connection = Double::for(QueueContract::class)->strict();
        $queue->expects('connection')->times(2)->returns($connection);
        $connection->expects('bulk')->times(2);

        $repository = new DatabaseBatchRepository(new BatchFactory($queue), $this->app->make('db'), 'job_batches');

        // The failure callback stands in for a retried job adding work just as the batch finishes.
        $batch = $repository->store((new PendingBatch($this->app, Collection::make()))->allowFailures(
            fn (Batch $batch) => $batch->add([new TestBatchJob]),
        ));

        $batch = $batch->add([new TestBatchJob]);
        $batch->recordFailedJob('first-id', new RuntimeException('Something went wrong.'));

        $batch = $batch->fresh();
        $this->assertFalse($batch->finished());
        $this->assertSame(2, $batch->pendingJobs);
    }

    public function testFinishingWithFailuresKeepsAnEarlierCancellationTime(): void
    {
        $queue = Double::for(Factory::class)->strict();

        $batch = $this->createTestBatch($queue, $allowFailures = true);

        $connection = Double::for(QueueContract::class)->strict();
        $queue->expects('connection')->with('test-connection')->returns($connection);

        $connection->expects('bulk');

        $batch = $batch->add([new TestBatchJob]);

        $this->app->make('db')->table('job_batches')->where('id', $batch->id)->update([
            'cancelled_at' => 100,
            'finished_at' => 100,
        ]);

        $batch->recordFailedJob('first-id', new RuntimeException('Something went wrong.'));

        $this->assertSame(100, $batch->fresh()->finishedAt->getTimestamp());
        $batch->recordSuccessfulJob('first-id');
        $this->assertSame(100, $batch->fresh()->finishedAt->getTimestamp());
    }

    public function testBatchesNotAllowingFailuresAreOnlyFinishedByCancellation(): void
    {
        $queue = Double::for(Factory::class)->strict();

        $batch = $this->createTestBatch($queue);

        $connection = Double::for(QueueContract::class)->strict();
        $queue->expects('connection')->with('test-connection')->returns($connection);

        $connection->expects('bulk');

        $batch = $batch->add([new TestBatchJob]);

        $finishedWhenStarted = null;
        $this->app->make(EventDispatcher::class)->listen(BatchStarted::class, function (BatchStarted $event) use (&$finishedWhenStarted): void {
            $finishedWhenStarted = $event->batch->fresh()->finished();
        });

        $batch->recordFailedJob('first-id', new RuntimeException('Something went wrong.'));

        $this->assertFalse($finishedWhenStarted);
        $this->assertTrue($batch->fresh()->cancelled());
    }

    public function testRepositoriesWithoutFirstPassReportingFinishBatchesAfterTheCallbacks(): void
    {
        $repository = Double::for(BatchRepository::class)->strict();
        $batch = new Batch(Double::for(Factory::class)->strict(), $repository, 'batch', 'batch', 1, 1, 0, [], [
            'allowFailures' => true,
        ], CarbonImmutable::now());
        $repository->expects('incrementFailedJobs')->with('batch', 'job', null)->returns(new UpdatedBatchJobCounts(1, 1));
        $repository->expects('decrementPendingJobs')->with('batch', 'job')->returns(new UpdatedBatchJobCounts(0, 1));
        $repository->expects('markAsFinished')->with('batch')->times(2);

        $batch->recordFailedJob('job', new RuntimeException('Something went wrong.'));
        $batch->recordSuccessfulJob('job');
    }

    public function testWorkAddedByLastSuccessProgressCallbackReopensBatch(): void
    {
        Queue::fake();
        $batch = Bus::batch([new TestBatchJob])->progress(static function (Batch $batch): void {
            if ($batch->totalJobs === 1) {
                $batch->add(new TestBatchJob);
            }
        })->dispatch();

        $batch->recordSuccessfulJob('first');
        $this->assertSame(1, $batch->fresh()->pendingJobs);
        $this->assertFalse($batch->fresh()->finished());
        $batch->recordSuccessfulJob('added');
        $this->assertTrue($batch->fresh()->finished());
    }

    public function testOutstandingRetryIdsLoadOnceWhenReadAndRemainWritable(): void
    {
        $batch = $this->createTestBatch(new QueueFake($this->app), true)->add([new TestBatchJob, new TestBatchJob]);
        $batch->recordFailedJob('position', null, 'original-retry');
        $repository = $this->app->make(DatabaseBatchRepository::class);
        $connection = $repository->getConnection();
        $connection->enableQueryLog();

        try {
            $batch->recordSuccessfulJob('other');
            $loaded = $repository->find($batch->id);
            $listed = $repository->get(1)[0];
            $this->assertSame($loaded->id, $listed->id);
            $reads = fn (): int => count(array_filter(
                $connection->getQueryLog(),
                static fn (array $query): bool => str_starts_with($query['query'], 'select') && str_contains($query['query'], 'job_batch_results')
            ));
            $this->assertSame(0, $reads());
            $this->assertSame('original-retry', $loaded->failedJobIds[0]);
            $reference = &$loaded->failedJobIds;
            $reference[] = 'local';
            $this->assertSame(['original-retry', 'local'], $loaded->failedJobIds);
            $listed->failedJobIds = ['assigned'];
            $this->assertSame(['assigned'], $listed->failedJobIds);
            $this->assertSame(1, $reads());
        } finally {
            $connection->disableQueryLog();
        }
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
        $queue = Double::for(Factory::class)->strict();

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

        $connection = Double::for(QueueContract::class)->strict();
        $queue->expects('connection')
            ->with('test-connection')
            ->returns($connection);

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
        $queue = new QueueFake($this->app);

        $batch = $this->createTestBatch($queue);

        $batch->cancel();

        $batch = $batch->fresh();

        $this->assertTrue($batch->cancelled());
    }

    public function testBatchCancelledEventIsDispatched(): void
    {
        $events = new EventFake(new EventsDispatcher);
        $this->app->instance(EventDispatcher::class, $events);

        $queue = new QueueFake($this->app);
        $batch = $this->createTestBatch($queue);

        $exception = new RuntimeException('Something went wrong.');

        $batch->cancel($exception);

        $events->assertDispatchedOnce(BatchCanceled::class);
        $events->assertDispatched(BatchCanceled::class, function (BatchCanceled $event) use ($batch, $exception): bool {
            return $event->batch->id === $batch->id
                && $event->batch->cancelled()
                && $event->exception === $exception;
        });
    }

    public function testBatchCanBeDeleted(): void
    {
        $queue = new QueueFake($this->app);

        $batch = $this->createTestBatch($queue);

        $batch->delete();

        $batch = $batch->fresh();

        $this->assertNull($batch);
    }

    public function testDeletedBatchIgnoresLateJobResultsAndCallbacks(): void
    {
        $queue = Double::for(Factory::class)->strict();
        $batch = $this->createTestBatch($queue, $allowFailures = true);

        $job = new class {
            use Batchable;
        };

        $connection = Double::for(QueueContract::class)->strict();
        $queue->expects('connection')
            ->with('test-connection')
            ->returns($connection);

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
        $queue = new QueueFake($this->app);

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
        $queue = Double::for(Factory::class)->strict();

        $batch = $this->createTestBatch($queue);

        $chainHeadJob = new ChainHeadJob;

        $secondJob = new SecondTestJob;

        $thirdJob = new ThirdTestJob;

        $connection = Double::for(QueueContract::class)->strict();
        $queue->expects('connection')
            ->with('test-connection')
            ->returns($connection);

        $connection->expects('bulk')->with(Argument::satisfies(function (array $jobs) use ($chainHeadJob, $secondJob, $thirdJob): bool {
            return
                $jobs[0] === $chainHeadJob
                && Batch::matchesChainedJob($secondJob, $jobs[0]->chained[0])
                && Batch::matchesChainedJob($thirdJob, $jobs[0]->chained[1]);
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

    public function testObjectAssertionsCompareTheApplicationJobsAtRepeatedPositions(): void
    {
        Queue::fake();
        $head = new ChainHeadJob;
        $tail = (new SecondTestJob)->onQueue('tail-route');
        $batch = Bus::batch([[$head, $tail, $tail]])->dispatch();
        $expected = (new SecondTestJob)->onQueue('tail-route')->withBatchId($batch->id);

        $head->assertHasChain([$tail, $tail]);
        Queue::assertPushedWithChain(ChainHeadJob::class, [$expected, $expected]);
        Bus::fake();
        $head->dispatchNextJobInChain();
        Bus::assertChained([$expected, $expected]);
    }

    public function testChainedJobsInBatchPreserveTheirQueueWhenBatchHasNoQueue(): void
    {
        $queue = Double::for(Factory::class)->strict();

        $repository = new DatabaseBatchRepository(
            new BatchFactory($queue),
            $this->app->make('db'),
            'job_batches'
        );

        $pendingBatch = (new PendingBatch($this->app, collect()))
            ->onConnection('test-connection');

        $batch = $repository->store($pendingBatch);

        $firstJob = (new ChainHeadJob)->onQueue('custom-queue');
        $secondJob = (new SecondTestJob)->onQueue('custom-queue');

        $connection = Double::for(QueueContract::class)->strict();
        $queue->expects('connection')
            ->with('test-connection')
            ->returns($connection);

        $connection->expects('bulk')->with(Argument::type('array'), '', null);

        $batch->add([
            [$firstJob, $secondJob],
        ]);

        $this->assertSame('custom-queue', $secondJob->queue);
        $this->assertSame('custom-queue', $firstJob->queue);
    }

    public function testChainedJobsPreserveTheirRoutesWhenTheBatchHasNone(): void
    {
        $queue = Double::for(Factory::class)->strict();

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

        $connection = Double::for(QueueContract::class)->strict();
        $queue->expects('connection')
            ->with(null)
            ->returns($connection);

        $connection->expects('bulk')->with(Argument::type('array'), '', null);

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

        Queue::assertPushedWithChain(ChainedBatch::class, [ChainedBatch::class, CallQueuedClosure::class]);
    }

    public function testOptionsSerializationOnPostgres(): void
    {
        $pendingBatch = (new PendingBatch($this->app, Collection::make()))
            ->onQueue('test-queue');

        $connection = Double::for(PostgresConnection::class)->strict();
        $resolver = Double::for(ConnectionResolverInterface::class)->strict();
        $resolver->allows('connection')->returns($connection);
        $builder = Double::for(Builder::class)->strict();

        $connection->expects('table')->times(2)->returns($builder);
        $builder->expects('insert')->with(Argument::satisfies(static function (array $values) use ($pendingBatch): bool {
            return unserialize(base64_decode($values['options'])) === $pendingBatch->options;
        }))->returns(true);
        $builder->expects('useWritePdo')->returns($builder);
        $builder->expects('where')->returns($builder);
        $builder->expects('first')->returns((object) [
            'id' => 'test-id',
            'name' => '',
            'total_jobs' => 0,
            'pending_jobs' => 0,
            'failed_jobs' => 0,
            'outstanding_failed_jobs' => 0,
            'options' => base64_encode(serialize($pendingBatch->options)),
            'created_at' => time(),
            'cancelled_at' => null,
            'finished_at' => null,
        ]);

        $repository = new DatabaseBatchRepository(
            new BatchFactory(Double::for(Factory::class)->strict()),
            $resolver,
            'job_batches'
        );

        $repository->store($pendingBatch);
    }

    #[TestWith([false], 'ordinary restoration failure')]
    #[TestWith([true], 'wrapped cancellation')]
    public function testOptionsRestorationFallbackDoesNotSwallowCancellation(bool $canceled): void
    {
        $factory = Double::for(BatchFactory::class)->strict();
        $connection = Double::for(PostgresConnection::class)->strict();
        $resolver = Double::for(ConnectionResolverInterface::class)->strict();
        $resolver->allows('connection')->returns($connection);
        $builder = Double::for(Builder::class)->strict();
        $connection->expects('table')->returns($builder);
        $builder->expects('useWritePdo')->returns($builder);
        $builder->expects('where')->returns($builder);
        $builder->expects('first')->returns((object) [
            'id' => 'batch', 'name' => '', 'total_jobs' => 0, 'pending_jobs' => 0,
            'failed_jobs' => 0, 'outstanding_failed_jobs' => 0,
            'options' => serialize([new BatchOptionWithFailingRestoration($canceled)]),
            'created_at' => now()->getTimestamp(), 'cancelled_at' => null, 'finished_at' => null,
        ]);
        $repository = new DatabaseBatchRepository($factory, $resolver, 'job_batches');
        if ($canceled) {
            $factory->expects('make')->never();
        } else {
            $factory->expects('make')
                ->with(Argument::same($repository), 'batch', '', 0, 0, 0, [], [], Argument::remaining())
                ->returns(Double::for(Batch::class)->strict());
        }
        $caught = null;

        try {
            $repository->find('batch');
        } catch (Throwable $exception) {
            $caught = $exception;
        }

        if ($canceled) {
            $this->assertInstanceOf(RuntimeException::class, $caught);
            $this->assertTrue(Coroutine::causedByCancellation($caught));
        } else {
            $this->assertNull($caught);
        }
    }

    #[DataProvider('serializedOptions')]
    public function testOptionsUnserializeOnPostgres(string $serialize, array $options): void
    {
        $factory = Double::for(BatchFactory::class)->strict();

        $connection = Double::for(PostgresConnection::class)->strict();
        $resolver = Double::for(ConnectionResolverInterface::class)->strict();
        $resolver->allows('connection')->returns($connection);

        $builder = Double::for(Builder::class)->strict();
        $connection->expects('table')->returns($builder);
        $builder->expects('useWritePdo')->returns($builder);
        $builder->expects('where')->returns($builder);
        $builder->expects('first')
            ->returns((object) [
                'id' => '',
                'name' => '',
                'total_jobs' => '',
                'pending_jobs' => '',
                'failed_jobs' => '',
                'outstanding_failed_jobs' => 0,
                'options' => $serialize,
                'created_at' => now()->getTimestamp(),
                'cancelled_at' => null,
                'finished_at' => null,
            ]);

        $batch = new DatabaseBatchRepository($factory, $resolver, 'job_batches');

        $factory->expects('make')
            ->with(Argument::same($batch), '', '', 0, 0, 0, [], $options, Argument::remaining())
            ->returns(Double::for(Batch::class)->strict());

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

class BatchOptionWithFailingRestoration
{
    /**
     * Configure a canceled or ordinary application-object restoration failure.
     */
    public function __construct(private bool $canceled)
    {
    }

    /**
     * Fail while restoring a stored batch option.
     */
    public function __wakeup(): void
    {
        throw new RuntimeException('Option restoration failed.', previous: $this->canceled ? new CanceledException : null);
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

class RetriedBatchJob implements ShouldQueue
{
    use Batchable;
    use InteractsWithQueue;
    use Queueable;

    public static bool $handled = false;

    /**
     * Do the job's work while its batch still includes it.
     */
    public function handle(): void
    {
        if ($this->batching()) {
            static::$handled = true;
        }
    }
}

class RetriedBatchableOnlyJob implements ShouldQueue
{
    use Batchable;
    use Queueable;

    public static bool $handled = false;

    public ?Job $job = null;

    /**
     * Do the job's work while its batch still includes it.
     */
    public function handle(): void
    {
        // The application's own job property does not identify this job to its batch.
        $this->job = new FakeJob(['uuid' => 'referenced-job']);

        if ($this->batching()) {
            static::$handled = true;
        }
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
