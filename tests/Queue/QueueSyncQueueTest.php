<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue;

use Exception;
use Hypervel\Bus\Dispatcher as BusDispatcher;
use Hypervel\Container\Container;
use Hypervel\Contracts\Bus\Dispatcher;
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

    protected function setUp(): void
    {
        parent::setUp();

        if (! class_exists('Illuminate\Queue\CallQueuedHandler', autoload: false)) {
            class_alias(CallQueuedHandler::class, 'Illuminate\Queue\CallQueuedHandler');
        }
    }

    public function testPushShouldFireJobInstantly()
    {
        unset($_SERVER['__sync.test']);

        $sync = new SyncQueue;
        $sync->setConnectionName('sync');
        $container = $this->getContainer();
        $sync->setContainer($container);
        $sync->setConnectionName('sync');

        $sync->push(SyncQueueTestHandler::class, ['foo' => 'bar']);
        $this->assertInstanceOf(SyncJob::class, $_SERVER['__sync.test'][0]);
        $this->assertEquals(['foo' => 'bar'], $_SERVER['__sync.test'][1]);
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
        $events->shouldReceive('hasListeners')->once()->with(JobProcessing::class)->andReturnFalse();
        $events->shouldReceive('hasListeners')->once()->with(JobProcessed::class)->andReturnFalse();
        $events->shouldReceive('hasListeners')->once()->with(JobAttempted::class)->andReturnFalse();
        $events->shouldReceive('dispatch')->never();
        $container->instance('events', $events);
        $container->instance(EventDispatcher::class, $events);
        $sync->setContainer($container);

        $sync->push(SyncQueueTestHandler::class, ['foo' => 'bar']);

        $this->assertInstanceOf(SyncJob::class, $_SERVER['__sync.test'][0]);
    }

    public function testFailedJobGetsHandledWhenAnExceptionIsThrown()
    {
        unset($_SERVER['__sync.failed']);

        $sync = new SyncQueue;
        $sync->setConnectionName('sync');
        $container = $this->getContainer();
        $events = m::mock(EventDispatcher::class);
        $events->shouldReceive('hasListeners')->once()->with(JobProcessing::class)->andReturnTrue();
        $events->shouldReceive('hasListeners')->once()->with(JobExceptionOccurred::class)->andReturnTrue();
        $events->shouldReceive('hasListeners')->once()->with(JobFailed::class)->andReturnTrue();
        $events->shouldReceive('hasListeners')->once()->with(JobAttempted::class)->andReturnTrue();
        $events->shouldReceive('dispatch')->times(4);
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

    public function testFailedJobHasAccessToJobInstance()
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

        SyncQueue::createPayloadUsing(function ($connection, $queue, $payload) {
            return ['data' => ['extra' => 'extraValue']];
        });

        try {
            $sync->push(new FailingSyncQueueJob);
        } catch (LogicException) {
            $this->assertSame('extraValue', $_SERVER['__sync.failed']);
        }
    }

    public function testCreatesPayloadObject()
    {
        $sync = new SyncQueue;
        $sync->setConnectionName('sync');
        $container = $this->getContainer();
        $events = new EventsDispatcher($container);
        $container->instance('events', $events);
        $container->instance(EventDispatcher::class, $events);
        $dispatcher = m::mock(Dispatcher::class);
        $dispatcher->shouldReceive('getCommandHandler')->once()->andReturn(false);
        $dispatcher->shouldReceive('dispatchNow')->once();
        $container->instance(Dispatcher::class, $dispatcher);
        $sync->setContainer($container);

        SyncQueue::createPayloadUsing(function ($connection, $queue, $payload) {
            return ['data' => ['extra' => 'extraValue']];
        });

        try {
            $sync->push(new SyncQueueJob);
        } catch (LogicException $e) {
            $this->assertSame('extraValue', $e->getMessage());
        }

        SyncQueue::createPayloadUsing(null);
    }

    public function testItAddsATransactionCallbackForAfterCommitJobs()
    {
        $sync = new SyncQueue;
        $sync->setConnectionName('sync');
        $container = $this->getContainer();
        $transactionManager = m::mock(DatabaseTransactionsManager::class);
        $transactionManager->shouldReceive('addCallback')->once()->andReturn(null);
        $container->instance('db.transactions', $transactionManager);

        $sync->setContainer($container);
        $sync->push(new SyncQueueAfterCommitJob);
    }

    public function testItAddsATransactionCallbackForInterfaceBasedAfterCommitJobs()
    {
        $sync = new SyncQueue;
        $sync->setConnectionName('sync');
        $container = $this->getContainer();
        $transactionManager = m::mock(DatabaseTransactionsManager::class);
        $transactionManager->shouldReceive('addCallback')->once()->andReturn(null);
        $container->instance('db.transactions', $transactionManager);

        $sync->setContainer($container);
        $sync->push(new SyncQueueAfterCommitInterfaceJob);
    }

    public function testItAddsATransactionCallbackForAfterCommitUniqueJobs()
    {
        $sync = new SyncQueue;
        $sync->setConnectionName('sync');
        $container = $this->getContainer();
        $transactionManager = m::mock(DatabaseTransactionsManager::class);
        $transactionManager->shouldReceive('addCallback')->once()->andReturn(null);
        $transactionManager->shouldReceive('addCallbackForRollback')->once()->andReturn(null);
        $container->instance('db.transactions', $transactionManager);

        $sync->setContainer($container);
        $sync->push(new SyncQueueAfterCommitUniqueJob);
    }

    public function testItAddsATransactionRollbackCallbackForAfterCommitDebouncedJobs(): void
    {
        $sync = new SyncQueue;
        $sync->setConnectionName('sync');
        $container = $this->getContainer();
        $transactionManager = m::mock(DatabaseTransactionsManager::class);
        $transactionManager->shouldReceive('addCallback')->once()->andReturn(null);
        $transactionManager->shouldReceive('addCallbackForRollback')->once()->andReturn(null);
        $container->instance('db.transactions', $transactionManager);

        $sync->setContainer($container);
        $sync->push(new SyncQueueAfterCommitDebouncedJob);
    }

    public function testItAddsATransactionCallbackForInterfaceBasedAfterCommitUniqueJobs()
    {
        $sync = new SyncQueue;
        $sync->setConnectionName('sync');
        $container = $this->getContainer();
        $transactionManager = m::mock(DatabaseTransactionsManager::class);
        $transactionManager->shouldReceive('addCallback')->once()->andReturn(null);
        $transactionManager->shouldReceive('addCallbackForRollback')->once()->andReturn(null);
        $container->instance('db.transactions', $transactionManager);

        $sync->setContainer($container);
        $sync->push(new SyncQueueAfterCommitInterfaceUniqueJob);
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
    public function getQueueableId(): mixed
    {
        return 1;
    }

    public function getQueueableConnection(): ?string
    {
        return null;
    }

    public function getQueueableRelations(): array
    {
        return [];
    }
}

class SyncQueueTestHandler
{
    public function fire($job, $data)
    {
        $_SERVER['__sync.test'] = func_get_args();
    }
}

class FailingSyncQueueTestHandler
{
    public function fire($job, $data)
    {
        throw new Exception;
    }

    public function failed()
    {
        $_SERVER['__sync.failed'] = true;
    }
}

class CancelingSyncQueueTestHandler
{
    public static ?Channel $gate = null;

    public static bool $failed = false;

    public function fire(): never
    {
        static::$gate?->push(true);

        throw new RuntimeException('Cancellation was not delivered.');
    }

    public function failed(): void
    {
        static::$failed = true;
    }

    public static function reset(): void
    {
        static::$gate = null;
        static::$failed = false;
    }
}

class CancelingSerializationJob
{
    public static ?Channel $gate = null;

    public function __serialize(): array
    {
        static::$gate?->push(true);

        throw new RuntimeException('Cancellation was not delivered.');
    }
}

class FailingOrderedSyncQueueTestHandler
{
    public function fire(): never
    {
        SyncQueueEventOrder::$events[] = 'fire';

        throw new RuntimeException('Sync job failed.');
    }

    public function failed(): void
    {
    }
}

class OrderedSyncQueueTestHandler
{
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

    public static function reset(): void
    {
        self::$events = [];
        self::$attempted = null;
    }
}

class FailingSyncQueueJob implements ShouldQueue
{
    use InteractsWithQueue;

    public function handle(): void
    {
        throw new LogicException;
    }

    public function failed(): void
    {
        $payload = $this->job->payload();

        $_SERVER['__sync.failed'] = $payload['data']['extra'];
    }
}

class SyncQueueJob implements ShouldQueue
{
    use InteractsWithQueue;

    public function handle()
    {
        throw new LogicException($this->getValueFromJob('extra'));
    }

    public function getValueFromJob($key)
    {
        $payload = $this->job->payload();

        return $payload['data'][$key] ?? null;
    }
}

class SyncQueueAfterCommitJob
{
    use InteractsWithQueue;

    public $afterCommit = true;

    public function handle()
    {
    }
}

class SyncQueueAfterCommitInterfaceJob implements ShouldQueueAfterCommit
{
    use InteractsWithQueue;

    public function handle()
    {
    }
}

class SyncQueueAfterCommitUniqueJob implements ShouldBeUnique
{
    use InteractsWithQueue;

    public $afterCommit = true;

    public function handle(): void
    {
    }
}

class SyncQueueAfterCommitDebouncedJob
{
    use InteractsWithQueue;

    public bool $afterCommit = true;

    public string $debounceOwner = 'owner-token';

    public function handle(): void
    {
    }
}

class SyncQueueAfterCommitInterfaceUniqueJob implements ShouldBeUnique, ShouldQueueAfterCommit
{
    use InteractsWithQueue;

    public function handle(): void
    {
    }
}
