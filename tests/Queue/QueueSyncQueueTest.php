<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue;

use Exception;
use Hypervel\Bus\Dispatcher as BusDispatcher;
use Hypervel\Bus\DispatchLockContext;
use Hypervel\Container\Container;
use Hypervel\Contracts\Bus\Dispatcher as DispatcherContract;
use Hypervel\Contracts\Cache\Repository as Cache;
use Hypervel\Contracts\Container\Container as ContainerContract;
use Hypervel\Contracts\Events\Dispatcher as EventDispatcher;
use Hypervel\Contracts\Queue\QueueableEntity;
use Hypervel\Contracts\Queue\ShouldBeUnique;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Contracts\Queue\ShouldQueueAfterCommit;
use Hypervel\Database\DatabaseTransactionsManager;
use Hypervel\Engine\Channel;
use Hypervel\Engine\Coroutine as EngineCoroutine;
use Hypervel\Events\Dispatcher as EventsDispatcher;
use Hypervel\Queue\CallQueuedHandler;
use Hypervel\Queue\Events\JobAttempted;
use Hypervel\Queue\Events\JobExceptionOccurred;
use Hypervel\Queue\Events\JobFailed;
use Hypervel\Queue\Events\JobProcessed;
use Hypervel\Queue\Events\JobProcessing;
use Hypervel\Queue\Events\JobQueueingFailed;
use Hypervel\Queue\InteractsWithQueue;
use Hypervel\Queue\Jobs\SyncJob;
use Hypervel\Queue\SyncQueue;
use Hypervel\Tests\TestCase;
use LogicException;
use Mockery as m;
use RuntimeException;
use Swoole\Coroutine\CanceledException;

class QueueSyncQueueTest extends TestCase
{
    public function testInspectionReturnsEmptyCollections(): void
    {
        $queue = new SyncQueue;

        $this->assertTrue($queue->pendingJobs()->isEmpty());
        $this->assertTrue($queue->delayedJobs()->isEmpty());
        $this->assertTrue($queue->reservedJobs()->isEmpty());
        $this->assertTrue($queue->allPendingJobs()->isEmpty());
        $this->assertTrue($queue->allDelayedJobs()->isEmpty());
        $this->assertTrue($queue->allReservedJobs()->isEmpty());
    }

    /**
     * Set up the test environment.
     */
    protected function setUp(): void
    {
        parent::setUp();

        if (! class_exists('Illuminate\Queue\CallQueuedHandler', autoload: false)) {
            class_alias(CallQueuedHandler::class, 'Illuminate\Queue\CallQueuedHandler');
        }
    }

    public function testPushShouldFireJobInstantly(): void
    {
        unset($_SERVER['__sync.test']);

        $sync = new SyncQueue;
        $sync->setConnectionName('sync');
        $container = $this->getContainer();
        $sync->setContainer($container);

        $sync->push(SyncQueueTestHandler::class, ['foo' => 'bar']);
        $this->assertInstanceOf(SyncJob::class, $_SERVER['__sync.test'][0]);
        $this->assertEquals(['foo' => 'bar'], $_SERVER['__sync.test'][1]);
    }

    public function testPushRawExecutesPayloadInstantly(): void
    {
        unset($_SERVER['__sync.test']);

        $sync = new SyncQueue;
        $sync->setContainer($this->getContainer());
        $sync->setConnectionName('sync');

        $result = $sync->pushRaw(json_encode([
            'uuid' => 'raw-job',
            'job' => SyncQueueTestHandler::class,
            'data' => ['foo' => 'raw'],
        ], JSON_THROW_ON_ERROR));

        $this->assertSame(0, $result);
        $this->assertInstanceOf(SyncJob::class, $_SERVER['__sync.test'][0]);
        $this->assertSame(['foo' => 'raw'], $_SERVER['__sync.test'][1]);
    }

    public function testJobsReportTheirResolvedQueueName(): void
    {
        $sync = new SyncQueue;
        $sync->setConnectionName('sync-connection');
        $container = $this->getContainer();
        $events = new EventsDispatcher($container);
        $observed = [];

        $events->listen(JobProcessing::class, static function (JobProcessing $event) use (&$observed): void {
            $observed[] = [$event->connectionName, $event->job->getQueue()];
        });

        $container->instance('events', $events);
        $container->instance(EventDispatcher::class, $events);
        $sync->setContainer($container);

        foreach ([
            [null, 'sync'],
            ['', 'sync'],
            ['emails', 'emails'],
            // A queue named "0" is valid and must not be treated as empty.
            ['0', '0'],
        ] as [$queue, $expected]) {
            $observed = [];
            $sync->push(SyncQueueTestHandler::class, queue: $queue);
            $this->assertSame([['sync-connection', $expected]], $observed);
        }
    }

    public function testLifecycleEventsAreNotDispatchedWithoutListeners(): void
    {
        unset($_SERVER['__sync.test']);

        $sync = new SyncQueue;
        $sync->setConnectionName('sync');
        $container = $this->getContainer();
        $events = m::mock(EventDispatcher::class);
        $events->expects('hasListeners')->with(JobProcessing::class)->andReturnFalse();
        $events->expects('hasListeners')->with(JobProcessed::class)->andReturnFalse();
        $events->expects('hasListeners')->with(JobAttempted::class)->andReturnFalse();
        $events->shouldReceive('dispatch')->never();
        $container->instance('events', $events);
        $container->instance(EventDispatcher::class, $events);
        $sync->setContainer($container);

        $sync->push(SyncQueueTestHandler::class, ['foo' => 'bar']);

        $this->assertInstanceOf(SyncJob::class, $_SERVER['__sync.test'][0]);
    }

    public function testFailedJobGetsHandledWhenAnExceptionIsThrown(): void
    {
        unset($_SERVER['__sync.failed']);

        $sync = new SyncQueue;
        $sync->setConnectionName('sync');
        $container = $this->getContainer();
        $events = m::mock(EventDispatcher::class);
        $events->expects('hasListeners')->with(JobProcessing::class)->andReturnTrue();
        $events->expects('hasListeners')->with(JobExceptionOccurred::class)->andReturnTrue();
        $events->expects('hasListeners')->with(JobFailed::class)->andReturnTrue();
        $events->expects('hasListeners')->with(JobAttempted::class)->andReturnTrue();
        $events->expects('dispatch')->times(4);
        $container->instance('events', $events);
        $container->instance(EventDispatcher::class, $events);
        $sync->setContainer($container);

        try {
            $sync->push(FailingSyncQueueTestHandler::class, ['foo' => 'bar']);
        } catch (Exception) {
            $this->assertTrue($_SERVER['__sync.failed']);
        }
    }

    public function testProcessingAndAttemptedEventsSurroundSuccessfulSyncJob(): void
    {
        SyncQueueEventOrder::reset();

        try {
            $sync = new SyncQueue;
            $sync->setConnectionName('sync');
            $container = $this->getContainer();
            $events = new EventsDispatcher($container);
            $events->listen(JobProcessing::class, function (): void {
                SyncQueueEventOrder::$events[] = 'processing';
            });
            $events->listen(JobAttempted::class, function (JobAttempted $event): void {
                SyncQueueEventOrder::$events[] = 'attempted';
                SyncQueueEventOrder::$attempted = $event;
            });
            $container->instance('events', $events);
            $container->instance(EventDispatcher::class, $events);
            $sync->setContainer($container);

            $sync->push(OrderedSyncQueueTestHandler::class);

            $this->assertSame(['processing', 'fire', 'attempted'], SyncQueueEventOrder::$events);
            $this->assertNotNull(SyncQueueEventOrder::$attempted);
            $this->assertNull(SyncQueueEventOrder::$attempted->exception);
            $this->assertTrue(SyncQueueEventOrder::$attempted->successful());
        } finally {
            SyncQueueEventOrder::reset();
        }
    }

    public function testAttemptedEventRunsAfterThrownSyncJob(): void
    {
        SyncQueueEventOrder::reset();

        try {
            $sync = new SyncQueue;
            $sync->setConnectionName('sync');
            $container = $this->getContainer();
            $events = new EventsDispatcher($container);
            $events->listen(JobProcessing::class, function (): void {
                SyncQueueEventOrder::$events[] = 'processing';
            });
            $events->listen(JobAttempted::class, function (JobAttempted $event): void {
                SyncQueueEventOrder::$events[] = 'attempted';
                SyncQueueEventOrder::$attempted = $event;
            });
            $container->instance('events', $events);
            $container->instance(EventDispatcher::class, $events);
            $sync->setContainer($container);

            try {
                $sync->push(FailingOrderedSyncQueueTestHandler::class);
                $this->fail('Expected sync job exception was not thrown.');
            } catch (RuntimeException $exception) {
                $this->assertSame('Sync job failed.', $exception->getMessage());
            }

            $this->assertSame(['processing', 'fire', 'attempted'], SyncQueueEventOrder::$events);
            $this->assertNotNull(SyncQueueEventOrder::$attempted);
            $this->assertInstanceOf(RuntimeException::class, SyncQueueEventOrder::$attempted->exception);
            $this->assertSame('Sync job failed.', SyncQueueEventOrder::$attempted->exception->getMessage());
            $this->assertFalse(SyncQueueEventOrder::$attempted->successful());
        } finally {
            SyncQueueEventOrder::reset();
        }
    }

    public function testCancellationEscapesWithoutFailedOrCompletionEvents(): void
    {
        CancelingSyncQueueTestHandler::reset();
        $gate = $this->armCurrentCoroutineCancellation();
        CancelingSyncQueueTestHandler::$gate = $gate;
        $dispatched = [];
        $sync = new SyncQueue;
        $sync->setConnectionName('sync');
        $container = $this->getContainer();
        $events = new EventsDispatcher($container);

        foreach ([JobProcessing::class, JobProcessed::class, JobExceptionOccurred::class, JobAttempted::class, JobQueueingFailed::class] as $event) {
            $events->listen($event, static function (object $event) use (&$dispatched): void {
                $dispatched[] = $event::class;
            });
        }

        $container->instance('events', $events);
        $container->instance(EventDispatcher::class, $events);
        $sync->setContainer($container);

        try {
            $sync->push(CancelingSyncQueueTestHandler::class);
            $this->fail('Expected cancellation to escape the sync queue.');
        } catch (CanceledException) {
            $this->assertSame([JobProcessing::class], $dispatched);
            $this->assertFalse(CancelingSyncQueueTestHandler::$failed);
        } finally {
            CancelingSyncQueueTestHandler::reset();
        }
    }

    public function testCancellationDuringSerializationIsNotWrapped(): void
    {
        $sync = new SyncQueue;
        $sync->setConnectionName('sync');
        $sync->setContainer($this->getContainer());
        CancelingSerializationJob::$gate = $this->armCurrentCoroutineCancellation();

        try {
            $sync->push(new CancelingSerializationJob);
            $this->fail('Expected cancellation to escape payload serialization.');
        } catch (CanceledException) {
            $this->addToAssertionCount(1);
        } finally {
            CancelingSerializationJob::$gate = null;
        }
    }

    public function testFailedJobHasAccessToJobInstance(): void
    {
        unset($_SERVER['__sync.failed']);

        $sync = new SyncQueue;
        $sync->setConnectionName('sync');
        $container = $this->getContainer();
        $events = new EventsDispatcher($container);
        $container->instance('events', $events);
        $container->instance(EventDispatcher::class, $events);
        $container->instance(DispatcherContract::class, new BusDispatcher($container));
        $sync->setContainer($container);

        SyncQueue::createPayloadUsing(function (string $connection, ?string $queue, array $payload): array {
            return ['data' => ['extra' => 'extraValue']];
        });

        try {
            $sync->push(new FailingSyncQueueJob);
        } catch (LogicException) {
            $this->assertSame('extraValue', $_SERVER['__sync.failed']);

            return;
        }

        $this->fail('The failed job did not throw its exception.');
    }

    public function testCreatesPayloadObject(): void
    {
        $sync = new SyncQueue;
        $sync->setConnectionName('sync');
        $container = $this->getContainer();
        $events = new EventsDispatcher($container);
        $container->instance('events', $events);
        $container->instance(EventDispatcher::class, $events);
        $container->instance(DispatcherContract::class, new BusDispatcher($container));
        $sync->setContainer($container);

        SyncQueue::createPayloadUsing(function (string $connection, ?string $queue, array $payload): array {
            return ['data' => ['extra' => 'extraValue']];
        });

        $this->expectExceptionObject(new LogicException('extraValue'));

        $sync->push(new SyncQueueJob);
    }

    public function testItAddsATransactionCallbackForAfterCommitJobs(): void
    {
        $sync = new SyncQueue;
        $sync->setConnectionName('sync');
        $container = $this->getContainer();
        $transactionManager = m::mock(DatabaseTransactionsManager::class);
        $transactionManager->expects('addCallback')->andReturn(null);
        $transactionManager->shouldNotReceive('addCallbackForRollback');
        $container->instance('db.transactions', $transactionManager);

        $sync->setContainer($container);
        $sync->push(new SyncQueueAfterCommitJob);
    }

    public function testItAddsATransactionCallbackForInterfaceBasedAfterCommitJobs(): void
    {
        $sync = new SyncQueue;
        $sync->setConnectionName('sync');
        $container = $this->getContainer();
        $transactionManager = m::mock(DatabaseTransactionsManager::class);
        $transactionManager->expects('addCallback')->andReturn(null);
        $transactionManager->shouldNotReceive('addCallbackForRollback');
        $container->instance('db.transactions', $transactionManager);

        $sync->setContainer($container);
        $sync->push(new SyncQueueAfterCommitInterfaceJob);
    }

    public function testItAddsATransactionCallbackForAfterCommitUniqueJobs(): void
    {
        $sync = new SyncQueue;
        $sync->setConnectionName('sync');
        $container = $this->getContainer();
        $transactionManager = m::mock(DatabaseTransactionsManager::class);
        $transactionManager->expects('addCallback')->andReturn(null);
        $transactionManager->expects('addCallbackForRollback')->andReturn(null);
        $container->instance('db.transactions', $transactionManager);

        $job = new SyncQueueAfterCommitUniqueJob;
        DispatchLockContext::registerUnique($job, $container->make(Cache::class), null, 'unique-key', 'owner');

        $sync->setContainer($container);
        $sync->push($job);
    }

    public function testItAddsATransactionRollbackCallbackForAfterCommitDebouncedJobs(): void
    {
        $sync = new SyncQueue;
        $sync->setConnectionName('sync');
        $container = $this->getContainer();
        $transactionManager = m::mock(DatabaseTransactionsManager::class);
        $transactionManager->expects('addCallback')->andReturn(null);
        $transactionManager->expects('addCallbackForRollback')->andReturn(null);
        $container->instance('db.transactions', $transactionManager);

        $job = new SyncQueueAfterCommitDebouncedJob;
        DispatchLockContext::registerDebounce($job, $container->make(Cache::class), 'debounce-key', 'owner');

        $sync->setContainer($container);
        $sync->push($job);
    }

    public function testItAddsATransactionCallbackForInterfaceBasedAfterCommitUniqueJobs(): void
    {
        $sync = new SyncQueue;
        $sync->setConnectionName('sync');
        $container = $this->getContainer();
        $transactionManager = m::mock(DatabaseTransactionsManager::class);
        $transactionManager->expects('addCallback')->andReturn(null);
        $transactionManager->expects('addCallbackForRollback')->andReturn(null);
        $container->instance('db.transactions', $transactionManager);

        $job = new SyncQueueAfterCommitInterfaceUniqueJob;
        DispatchLockContext::registerUnique($job, $container->make(Cache::class), null, 'unique-key', 'owner');

        $sync->setContainer($container);
        $sync->push($job);
    }

    public function testAfterCommitUniqueJobWithoutDispatchOwnershipDoesNotAddRollbackCallback(): void
    {
        $sync = new SyncQueue;
        $sync->setConnectionName('sync');
        $container = $this->getContainer();
        $transactionManager = m::mock(DatabaseTransactionsManager::class);
        $transactionManager->expects('addCallback')->andReturnNull();
        $transactionManager->shouldReceive('addCallbackForRollback')->never();
        $container->instance('db.transactions', $transactionManager);

        $sync->setContainer($container);
        $sync->push(new SyncQueueAfterCommitUniqueJob);
    }

    /**
     * Arm exact cancellation of the current coroutine at a controlled channel handoff.
     */
    private function armCurrentCoroutineCancellation(): Channel
    {
        $gate = new Channel(1);
        $coroutineId = EngineCoroutine::id();

        EngineCoroutine::create(static function () use ($coroutineId, $gate): void {
            $gate->pop();
            EngineCoroutine::cancelById($coroutineId, throwException: true);
        });

        return $gate;
    }

    /**
     * Create the container used by synchronous jobs.
     */
    protected function getContainer(): Container
    {
        $container = new Container;
        $container->instance(Cache::class, m::mock(Cache::class));
        $container->instance(ContainerContract::class, $container);
        Container::setInstance($container);

        return $container;
    }
}

class SyncQueueTestEntity implements QueueableEntity
{
    /**
     * Get the queueable identity.
     */
    public function getQueueableId(): mixed
    {
        return 1;
    }

    /**
     * Get the queueable connection.
     */
    public function getQueueableConnection(): ?string
    {
        return null;
    }

    /**
     * Get the queueable relationships.
     */
    public function getQueueableRelations(): array
    {
        return [];
    }
}

class SyncQueueTestHandler
{
    /**
     * Record the job and its data.
     */
    public function fire(SyncJob $job, array|string $data): void
    {
        $_SERVER['__sync.test'] = func_get_args();
    }
}

class FailingSyncQueueTestHandler
{
    /**
     * Fail the synchronous job.
     */
    public function fire(SyncJob $job, array $data): never
    {
        throw new Exception;
    }

    /**
     * Record the job failure.
     */
    public function failed(): void
    {
        $_SERVER['__sync.failed'] = true;
    }
}

class CancelingSyncQueueTestHandler
{
    public static ?Channel $gate = null;

    public static bool $failed = false;

    /**
     * Yield until the test cancels the job.
     */
    public function fire(): never
    {
        static::$gate?->push(true);

        throw new RuntimeException('Cancellation was not delivered.');
    }

    /**
     * Record an unexpected job failure.
     */
    public function failed(): void
    {
        static::$failed = true;
    }

    /**
     * Reset the cancellation fixture.
     */
    public static function reset(): void
    {
        static::$gate = null;
        static::$failed = false;
    }
}

class CancelingSerializationJob
{
    public static ?Channel $gate = null;

    /**
     * Yield until the test cancels serialization.
     */
    public function __serialize(): array
    {
        static::$gate?->push(true);

        throw new RuntimeException('Cancellation was not delivered.');
    }
}

class FailingOrderedSyncQueueTestHandler
{
    /**
     * Record execution before failing the job.
     */
    public function fire(): never
    {
        SyncQueueEventOrder::$events[] = 'fire';

        throw new RuntimeException('Sync job failed.');
    }

    /**
     * Handle the expected job failure.
     */
    public function failed(): void
    {
    }
}

class OrderedSyncQueueTestHandler
{
    /**
     * Record the job execution.
     */
    public function fire(): void
    {
        SyncQueueEventOrder::$events[] = 'fire';
    }
}

class SyncQueueEventOrder
{
    /** @var list<string> */
    public static array $events = [];

    public static ?JobAttempted $attempted = null;

    /**
     * Reset the recorded job events.
     */
    public static function reset(): void
    {
        self::$events = [];
        self::$attempted = null;
    }
}

class FailingSyncQueueJob implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * Fail the job.
     */
    public function handle(): void
    {
        throw new LogicException;
    }

    /**
     * Record the extra payload data from the failed job.
     */
    public function failed(): void
    {
        $payload = $this->job->payload();

        $_SERVER['__sync.failed'] = $payload['data']['extra'];
    }
}

class SyncQueueJob implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * Expose the payload value through the job exception.
     */
    public function handle(): never
    {
        throw new LogicException($this->getValueFromJob('extra'));
    }

    /**
     * Get a value from the job payload.
     */
    public function getValueFromJob(string $key): mixed
    {
        $payload = $this->job->payload();

        return $payload['data'][$key] ?? null;
    }
}

class SyncQueueAfterCommitJob
{
    use InteractsWithQueue;

    public bool $afterCommit = true;

    /**
     * Handle the job.
     */
    public function handle(): void
    {
    }
}

class SyncQueueAfterCommitInterfaceJob implements ShouldQueueAfterCommit
{
    use InteractsWithQueue;

    /**
     * Handle the job.
     */
    public function handle(): void
    {
    }
}

class SyncQueueAfterCommitUniqueJob implements ShouldBeUnique
{
    use InteractsWithQueue;

    public bool $afterCommit = true;

    /**
     * Handle the job.
     */
    public function handle(): void
    {
    }
}

class SyncQueueAfterCommitDebouncedJob
{
    use InteractsWithQueue;

    public bool $afterCommit = true;

    public string $debounceOwner = 'owner-token';

    /**
     * Handle the job.
     */
    public function handle(): void
    {
    }
}

class SyncQueueAfterCommitInterfaceUniqueJob implements ShouldBeUnique, ShouldQueueAfterCommit
{
    use InteractsWithQueue;

    /**
     * Handle the job.
     */
    public function handle(): void
    {
    }
}
