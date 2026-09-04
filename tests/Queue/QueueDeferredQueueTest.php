<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue;

use DateInterval;
use Exception;
use Hypervel\Bus\DispatchLockContext;
use Hypervel\Bus\UniqueLock;
use Hypervel\Cache\ArrayStore as WorkerArrayStore;
use Hypervel\Cache\Repository;
use Hypervel\Container\Container;
use Hypervel\Contracts\Cache\Repository as Cache;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Queue\QueueableEntity;
use Hypervel\Contracts\Queue\ShouldBeUnique;
use Hypervel\Contracts\Queue\ShouldQueueAfterCommit;
use Hypervel\Coordinator\Timer;
use Hypervel\Database\DatabaseTransactionsManager;
use Hypervel\Engine\Channel;
use Hypervel\Engine\Coroutine as EngineCoroutine;
use Hypervel\Events\Dispatcher as EventsDispatcher;
use Hypervel\Queue\DeferredQueue;
use Hypervel\Queue\Events\JobAttempted;
use Hypervel\Queue\Events\JobExceptionOccurred;
use Hypervel\Queue\Events\JobFailed;
use Hypervel\Queue\Events\JobProcessing;
use Hypervel\Queue\InteractsWithQueue;
use Hypervel\Queue\Jobs\SyncJob;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Tests\TestCase;
use Mockery as m;
use RuntimeException;
use Throwable;

use function Hypervel\Coroutine\run;

class QueueDeferredQueueTest extends TestCase
{
    protected bool $runTestsInCoroutine = false;

    public function testPushShouldDefer()
    {
        unset($_SERVER['__deferred.test']);

        $deferred = new DeferredQueue;
        $deferred->setConnectionName('deferred');
        $container = $this->getContainer();
        $deferred->setContainer($container);
        $deferred->setConnectionName('deferred');

        run(fn () => $deferred->push(DeferredQueueTestHandler::class, ['foo' => 'bar']));

        $this->assertInstanceOf(SyncJob::class, $_SERVER['__deferred.test'][0]);
        $this->assertEquals(['foo' => 'bar'], $_SERVER['__deferred.test'][1]);
    }

    public function testPushRawDefersPayloadExecution(): void
    {
        unset($_SERVER['__deferred.test']);

        $deferred = new DeferredQueue;
        $deferred->setContainer($this->getContainer());
        $deferred->setConnectionName('deferred');

        run(fn () => $deferred->pushRaw(json_encode([
            'uuid' => 'raw-job',
            'job' => DeferredQueueTestHandler::class,
            'data' => ['foo' => 'raw'],
        ], JSON_THROW_ON_ERROR)));

        $this->assertInstanceOf(SyncJob::class, $_SERVER['__deferred.test'][0]);
        $this->assertSame(['foo' => 'raw'], $_SERVER['__deferred.test'][1]);
    }

    public function testJobsReportTheirResolvedQueueName(): void
    {
        $deferred = new DeferredQueue;
        $deferred->setConnectionName('deferred-connection');
        $container = $this->getContainer();
        $events = new EventsDispatcher($container);
        $observed = [];

        $events->listen(JobProcessing::class, static function (JobProcessing $event) use (&$observed): void {
            $observed[] = [$event->connectionName, $event->job->getQueue()];
        });

        $container->instance('events', $events);
        $container->instance(Dispatcher::class, $events);
        $deferred->setContainer($container);

        foreach ([
            [null, 'deferred'],
            ['', 'deferred'],
            ['emails', 'emails'],
            // A queue named "0" is valid and must not be treated as empty.
            ['0', '0'],
        ] as [$queue, $expected]) {
            $observed = [];
            run(fn () => $deferred->push(DeferredQueueTestHandler::class, queue: $queue));
            $this->assertSame([['deferred-connection', $expected]], $observed);
        }
    }

    public function testPushSnapshotsMutableJobBeforeCoroutineEnd(): void
    {
        DeferredQueueSnapshotHandler::$receivedValue = null;
        $data = (object) ['value' => 'before'];
        $deferred = new DeferredQueue;
        $deferred->setConnectionName('deferred');
        $deferred->setContainer($this->getContainer());

        run(function () use ($deferred, $data): void {
            $deferred->push(DeferredQueueSnapshotHandler::class, $data);
            $data->value = 'after';
        });

        $this->assertSame('before', DeferredQueueSnapshotHandler::$receivedValue);
    }

    public function testFailedJobGetsHandledWhenAnExceptionIsThrown()
    {
        unset($_SERVER['__deferred.failed']);

        $result = null;

        $deferred = new DeferredQueue;
        $deferred->setExceptionCallback(function ($exception) use (&$result) {
            $result = $exception;
        });
        $deferred->setConnectionName('deferred');
        $container = $this->getContainer();
        $events = m::mock(Dispatcher::class);
        $events->shouldReceive('hasListeners')->once()->with(JobProcessing::class)->andReturnTrue();
        $events->shouldReceive('hasListeners')->once()->with(JobExceptionOccurred::class)->andReturnTrue();
        $events->shouldReceive('hasListeners')->once()->with(JobFailed::class)->andReturnTrue();
        $events->shouldReceive('hasListeners')->once()->with(JobAttempted::class)->andReturnTrue();
        $events->shouldReceive('dispatch')->times(4);
        $container->instance('events', $events);
        $container->instance(Dispatcher::class, $events);
        $deferred->setContainer($container);

        run(function () use ($deferred) {
            $deferred->push(FailingDeferredQueueTestHandler::class, ['foo' => 'bar']);
        });

        $this->assertInstanceOf(Exception::class, $result);
        $this->assertTrue($_SERVER['__deferred.failed']);
    }

    public function testCancellationDoesNotInvokeDeferredExceptionCallback(): void
    {
        CancelingDeferredQueueTestHandler::reset();
        $result = null;
        $deferred = new DeferredQueue;
        $deferred->setExceptionCallback(static function (Throwable $exception) use (&$result): void {
            $result = $exception;
        });
        $deferred->setConnectionName('deferred');
        $container = $this->getContainer();
        $events = m::mock(Dispatcher::class);
        $events->shouldReceive('hasListeners')->once()->with(JobProcessing::class)->andReturnTrue();
        $events->shouldReceive('dispatch')->once();
        $container->instance('events', $events);
        $container->instance(Dispatcher::class, $events);
        $deferred->setContainer($container);

        try {
            run(function () use ($deferred): void {
                CancelingDeferredQueueTestHandler::$gate = $this->armCurrentCoroutineCancellation();
                $deferred->push(CancelingDeferredQueueTestHandler::class);
            });

            $this->assertNull($result);
            $this->assertFalse(CancelingDeferredQueueTestHandler::$failed);
        } finally {
            CancelingDeferredQueueTestHandler::reset();
        }
    }

    public function testItAddsATransactionCallbackForAfterCommitJobs()
    {
        $deferred = new DeferredQueue;
        $deferred->setConnectionName('deferred');
        $container = $this->getContainer();
        $transactionManager = m::mock(DatabaseTransactionsManager::class);
        $transactionManager->shouldReceive('addCallback')->once()->andReturn(null);
        $container->instance('db.transactions', $transactionManager);

        $deferred->setContainer($container);
        run(fn () => $deferred->push(new DeferredQueueAfterCommitJob));
    }

    public function testItAddsATransactionCallbackForInterfaceBasedAfterCommitJobs()
    {
        $deferred = new DeferredQueue;
        $deferred->setConnectionName('deferred');
        $container = $this->getContainer();
        $transactionManager = m::mock(DatabaseTransactionsManager::class);
        $transactionManager->shouldReceive('addCallback')->once()->andReturn(null);
        $container->instance('db.transactions', $transactionManager);

        $deferred->setContainer($container);
        run(fn () => $deferred->push(new DeferredQueueAfterCommitInterfaceJob));
    }

    public function testItAddsATransactionCallbackForAfterCommitUniqueJobs()
    {
        $deferred = new DeferredQueue;
        $deferred->setConnectionName('deferred');
        $container = $this->getContainer();
        $transactionManager = m::mock(DatabaseTransactionsManager::class);
        $transactionManager->shouldReceive('addCallback')->once()->andReturn(null);
        $transactionManager->shouldReceive('addCallbackForRollback')->once()->andReturn(null);
        $container->instance('db.transactions', $transactionManager);

        $job = new DeferredQueueAfterCommitUniqueJob;
        DispatchLockContext::registerUnique($job, $container->make(Cache::class), null, 'unique-key', 'owner');

        $deferred->setContainer($container);
        run(fn () => $deferred->push($job));
    }

    public function testItAddsATransactionRollbackCallbackForAfterCommitDebouncedJobs(): void
    {
        $deferred = new DeferredQueue;
        $deferred->setConnectionName('deferred');
        $container = $this->getContainer();
        $transactionManager = m::mock(DatabaseTransactionsManager::class);
        $transactionManager->shouldReceive('addCallback')->once()->andReturn(null);
        $transactionManager->shouldReceive('addCallbackForRollback')->once()->andReturn(null);
        $container->instance('db.transactions', $transactionManager);

        $job = new DeferredQueueAfterCommitDebouncedJob;
        DispatchLockContext::registerDebounce($job, $container->make(Cache::class), 'debounce-key', 'owner');

        $deferred->setContainer($container);
        run(fn () => $deferred->push($job));
    }

    public function testItAddsATransactionCallbackForInterfaceBasedAfterCommitUniqueJobs()
    {
        $deferred = new DeferredQueue;
        $deferred->setConnectionName('deferred');
        $container = $this->getContainer();
        $transactionManager = m::mock(DatabaseTransactionsManager::class);
        $transactionManager->shouldReceive('addCallback')->once()->andReturn(null);
        $transactionManager->shouldReceive('addCallbackForRollback')->once()->andReturn(null);
        $container->instance('db.transactions', $transactionManager);

        $job = new DeferredQueueAfterCommitInterfaceUniqueJob;
        DispatchLockContext::registerUnique($job, $container->make(Cache::class), null, 'unique-key', 'owner');

        $deferred->setContainer($container);
        run(fn () => $deferred->push($job));
    }

    public function testLaterSchedulesJobWithDelay()
    {
        $timer = m::mock(Timer::class);
        $timer->shouldReceive('after')
            ->once()
            ->with(5.0, m::type('Closure'))
            ->andReturnUsing(function ($delay, $callback) {
                $callback();
                return 1;
            });

        $deferred = new DeferredQueue(timer: $timer);
        $deferred->setConnectionName('deferred');
        $deferred->setContainer($this->getContainer());

        unset($_SERVER['__deferred.later.test']);

        run(fn () => $deferred->later(5, DeferredQueueLaterTestHandler::class, ['foo' => 'bar']));

        $this->assertInstanceOf(SyncJob::class, $_SERVER['__deferred.later.test'][0]);
        $this->assertEquals(['foo' => 'bar'], $_SERVER['__deferred.later.test'][1]);
    }

    public function testLaterWithDateInterval()
    {
        CarbonImmutable::setTestNow('2024-01-01 12:00:00');

        $timer = m::mock(Timer::class);
        $timer->shouldReceive('after')
            ->once()
            ->with(10.0, m::type('Closure'))
            ->andReturnUsing(function ($delay, $callback) {
                $callback();
                return 1;
            });

        $deferred = new DeferredQueue(timer: $timer);
        $deferred->setConnectionName('deferred');
        $deferred->setContainer($this->getContainer());

        unset($_SERVER['__deferred.later.test']);

        run(fn () => $deferred->later(new DateInterval('PT10S'), DeferredQueueLaterTestHandler::class, ['baz' => 'qux']));

        $this->assertInstanceOf(SyncJob::class, $_SERVER['__deferred.later.test'][0]);
        $this->assertEquals(['baz' => 'qux'], $_SERVER['__deferred.later.test'][1]);

        CarbonImmutable::setTestNow();
    }

    public function testLaterWithDateTime(): void
    {
        CarbonImmutable::setTestNow('2024-01-01 12:00:00');

        $timer = m::mock(Timer::class);
        $timer->shouldReceive('after')
            ->once()
            ->with(15.0, m::type('Closure'))
            ->andReturnUsing(function ($delay, $callback) {
                $callback();
                return 1;
            });

        $deferred = new DeferredQueue(timer: $timer);
        $deferred->setConnectionName('deferred');
        $deferred->setContainer($this->getContainer());

        unset($_SERVER['__deferred.later.test']);

        run(fn () => $deferred->later(CarbonImmutable::parse('2024-01-01 12:00:15'), DeferredQueueLaterTestHandler::class, ['test' => 'data']));

        $this->assertInstanceOf(SyncJob::class, $_SERVER['__deferred.later.test'][0]);
        $this->assertEquals(['test' => 'data'], $_SERVER['__deferred.later.test'][1]);

        CarbonImmutable::setTestNow();
    }

    public function testLaterAddsTransactionCallbackForAfterCommitJobs()
    {
        $timer = m::mock(Timer::class);
        $timer->shouldReceive('after')->once()->with(5.0, m::type('Closure'))->andReturn(1);

        $deferred = new DeferredQueue(timer: $timer);
        $deferred->setConnectionName('deferred');

        $container = $this->getContainer();
        $transactionManager = m::mock(DatabaseTransactionsManager::class);
        $transactionManager->shouldReceive('addCallback')
            ->once()
            ->andReturnUsing(function ($callback) {
                $callback();
                return null;
            });
        $container->instance('db.transactions', $transactionManager);
        $deferred->setContainer($container);

        run(fn () => $deferred->later(5, new DeferredQueueAfterCommitJob));
    }

    public function testLaterAddsTransactionCallbackForInterfaceBasedAfterCommitJobs()
    {
        $timer = m::mock(Timer::class);
        $timer->shouldReceive('after')->once()->with(5.0, m::type('Closure'))->andReturn(1);

        $deferred = new DeferredQueue(timer: $timer);
        $deferred->setConnectionName('deferred');

        $container = $this->getContainer();
        $transactionManager = m::mock(DatabaseTransactionsManager::class);
        $transactionManager->shouldReceive('addCallback')
            ->once()
            ->andReturnUsing(function ($callback) {
                $callback();
                return null;
            });
        $container->instance('db.transactions', $transactionManager);
        $deferred->setContainer($container);

        run(fn () => $deferred->later(5, new DeferredQueueAfterCommitInterfaceJob));
    }

    public function testLaterAddsTransactionCallbackForAfterCommitUniqueJobs()
    {
        $timer = m::mock(Timer::class);
        $timer->shouldReceive('after')->once()->with(5.0, m::type('Closure'))->andReturn(1);

        $deferred = new DeferredQueue(timer: $timer);
        $deferred->setConnectionName('deferred');

        $container = $this->getContainer();
        $transactionManager = m::mock(DatabaseTransactionsManager::class);
        $transactionManager->shouldReceive('addCallback')
            ->once()
            ->andReturnUsing(function ($callback) {
                $callback();
                return null;
            });
        $transactionManager->shouldReceive('addCallbackForRollback')->once()->andReturn(null);
        $container->instance('db.transactions', $transactionManager);
        $job = new DeferredQueueAfterCommitUniqueJob;
        DispatchLockContext::registerUnique($job, $container->make(Cache::class), null, 'unique-key', 'owner');
        $deferred->setContainer($container);

        run(fn () => $deferred->later(5, $job));
    }

    public function testLaterAddsTransactionRollbackCallbackForAfterCommitDebouncedJobs(): void
    {
        $timer = m::mock(Timer::class);
        $timer->shouldReceive('after')->once()->with(5.0, m::type('Closure'))->andReturn(1);

        $deferred = new DeferredQueue(timer: $timer);
        $deferred->setConnectionName('deferred');

        $container = $this->getContainer();
        $transactionManager = m::mock(DatabaseTransactionsManager::class);
        $transactionManager->shouldReceive('addCallback')
            ->once()
            ->andReturnUsing(function ($callback) {
                $callback();
                return null;
            });
        $transactionManager->shouldReceive('addCallbackForRollback')->once()->andReturn(null);
        $container->instance('db.transactions', $transactionManager);
        $job = new DeferredQueueAfterCommitDebouncedJob;
        DispatchLockContext::registerDebounce($job, $container->make(Cache::class), 'debounce-key', 'owner');
        $deferred->setContainer($container);

        run(fn () => $deferred->later(5, $job));
    }

    public function testLaterAddsTransactionCallbackForInterfaceBasedAfterCommitUniqueJobs()
    {
        $timer = m::mock(Timer::class);
        $timer->shouldReceive('after')->once()->with(5.0, m::type('Closure'))->andReturn(1);

        $deferred = new DeferredQueue(timer: $timer);
        $deferred->setConnectionName('deferred');

        $container = $this->getContainer();
        $transactionManager = m::mock(DatabaseTransactionsManager::class);
        $transactionManager->shouldReceive('addCallback')
            ->once()
            ->andReturnUsing(function ($callback) {
                $callback();
                return null;
            });
        $transactionManager->shouldReceive('addCallbackForRollback')->once()->andReturn(null);
        $container->instance('db.transactions', $transactionManager);
        $job = new DeferredQueueAfterCommitInterfaceUniqueJob;
        DispatchLockContext::registerUnique($job, $container->make(Cache::class), null, 'unique-key', 'owner');
        $deferred->setContainer($container);

        run(fn () => $deferred->later(5, $job));
    }

    public function testLaterClampsNegativeIntegerDelay()
    {
        $timer = m::mock(Timer::class);
        $timer->shouldReceive('after')->once()->with(0.0, m::type('Closure'))->andReturn(1);

        $deferred = new DeferredQueue(timer: $timer);
        $deferred->setConnectionName('deferred');
        $deferred->setContainer($this->getContainer());

        run(fn () => $deferred->later(-5, DeferredQueueLaterTestHandler::class));
    }

    public function testLaterClampsPastDateTimeInterface(): void
    {
        CarbonImmutable::setTestNow('2024-01-01 12:00:00');

        $timer = m::mock(Timer::class);
        $timer->shouldReceive('after')->once()->with(0.0, m::type('Closure'))->andReturn(1);

        $deferred = new DeferredQueue(timer: $timer);
        $deferred->setConnectionName('deferred');
        $deferred->setContainer($this->getContainer());

        run(fn () => $deferred->later(CarbonImmutable::parse('2024-01-01 11:59:50'), DeferredQueueLaterTestHandler::class));

        CarbonImmutable::setTestNow();
    }

    public function testLaterFailedJobGetsHandledWhenAnExceptionIsThrown()
    {
        unset($_SERVER['__deferred.failed']);

        $result = null;

        $timer = m::mock(Timer::class);
        $timer->shouldReceive('after')
            ->once()
            ->with(5.0, m::type('Closure'))
            ->andReturnUsing(function ($delay, $callback) {
                $callback();
                return 1;
            });

        $deferred = new DeferredQueue(timer: $timer);
        $deferred->setExceptionCallback(function ($exception) use (&$result) {
            $result = $exception;
        });
        $deferred->setConnectionName('deferred');
        $container = $this->getContainer();
        $events = m::mock(Dispatcher::class);
        $events->shouldReceive('hasListeners')->once()->with(JobProcessing::class)->andReturnTrue();
        $events->shouldReceive('hasListeners')->once()->with(JobExceptionOccurred::class)->andReturnTrue();
        $events->shouldReceive('hasListeners')->once()->with(JobFailed::class)->andReturnTrue();
        $events->shouldReceive('hasListeners')->once()->with(JobAttempted::class)->andReturnTrue();
        $events->shouldReceive('dispatch')->times(4);
        $container->instance('events', $events);
        $container->instance(Dispatcher::class, $events);
        $deferred->setContainer($container);

        run(fn () => $deferred->later(5, FailingDeferredQueueTestHandler::class, ['foo' => 'bar']));

        $this->assertInstanceOf(Exception::class, $result);
        $this->assertTrue($_SERVER['__deferred.failed']);
    }

    public function testLaterDoesNotExecuteJobWhenWorkerIsClosing()
    {
        unset($_SERVER['__deferred.later.test']);

        $cache = new Repository(new WorkerArrayStore);
        $job = new DeferredQueueAfterCommitUniqueJob;
        $lock = new UniqueLock($cache);
        $this->assertTrue($lock->acquireForDispatch($job));
        $metadata = DispatchLockContext::peekPayloadMetadata($job);
        $this->assertNotNull($metadata);

        $callback = null;
        $timer = m::mock(Timer::class);
        $timer->shouldReceive('after')
            ->once()
            ->with(5.0, m::type('Closure'))
            ->andReturnUsing(function ($delay, $scheduledCallback) use (&$callback) {
                $callback = $scheduledCallback;

                return 1;
            });

        $deferred = new DeferredQueue(timer: $timer);
        $deferred->setConnectionName('deferred');
        $deferred->setContainer($this->getContainer());

        run(fn () => $deferred->later(5, $job));

        $this->assertArrayNotHasKey('__deferred.later.test', $_SERVER);
        $this->assertNotNull($callback);
        $this->assertTrue(
            $cache->restoreLock($metadata['laravel_unique_job_key'], $metadata['laravel_unique_job_lock_owner'])->isLocked()
        );

        $callback(true);

        $this->assertFalse(
            $cache->restoreLock($metadata['laravel_unique_job_key'], $metadata['laravel_unique_job_lock_owner'])->isLocked()
        );
    }

    protected function getContainer(): Container
    {
        $container = new Container;
        $container->instance(Cache::class, m::mock(Cache::class));
        $container->instance(Dispatcher::class, new EventsDispatcher($container));
        Container::setInstance($container);

        return $container;
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
}

class DeferredQueueTestEntity implements QueueableEntity
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

class DeferredQueueTestHandler
{
    public function fire($job, $data)
    {
        $_SERVER['__deferred.test'] = func_get_args();
    }
}

class FailingDeferredQueueTestHandler
{
    public function fire($job, $data)
    {
        throw new Exception;
    }

    public function failed()
    {
        $_SERVER['__deferred.failed'] = true;
    }
}

class CancelingDeferredQueueTestHandler
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

class DeferredQueueAfterCommitJob
{
    use InteractsWithQueue;

    public $afterCommit = true;

    public function handle()
    {
    }
}

class DeferredQueueAfterCommitInterfaceJob implements ShouldQueueAfterCommit
{
    use InteractsWithQueue;

    public function handle()
    {
    }
}

class DeferredQueueAfterCommitUniqueJob implements ShouldBeUnique
{
    use InteractsWithQueue;

    public $afterCommit = true;

    public function handle(): void
    {
    }
}

class DeferredQueueAfterCommitDebouncedJob
{
    use InteractsWithQueue;

    public bool $afterCommit = true;

    public string $debounceOwner = 'owner-token';

    public function handle(): void
    {
    }
}

class DeferredQueueAfterCommitInterfaceUniqueJob implements ShouldBeUnique, ShouldQueueAfterCommit
{
    use InteractsWithQueue;

    public function handle(): void
    {
    }
}

class DeferredQueueLaterTestHandler
{
    public function fire(SyncJob $job, mixed $data): void
    {
        $_SERVER['__deferred.later.test'] = func_get_args();
    }
}

class DeferredQueueSnapshotHandler
{
    public static ?string $receivedValue = null;

    public function fire(SyncJob $job, array $data): void
    {
        static::$receivedValue = $data['value'];
    }
}
