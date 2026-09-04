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
use Hypervel\Queue\Attributes\Delay;
use Hypervel\Queue\BackgroundQueue;
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

class QueueBackgroundQueueTest extends TestCase
{
    protected bool $runTestsInCoroutine = false;

    public function testPushShouldRunInBackground()
    {
        unset($_SERVER['__background.test']);

        $background = new BackgroundQueue;
        $background->setConnectionName('background');
        $container = $this->getContainer();
        $background->setContainer($container);
        $background->setConnectionName('background');

        run(fn () => $background->push(BackgroundQueueTestHandler::class, ['foo' => 'bar']));

        $this->assertInstanceOf(SyncJob::class, $_SERVER['__background.test'][0]);
        $this->assertEquals(['foo' => 'bar'], $_SERVER['__background.test'][1]);
    }

    public function testPushRawRunsPayloadInBackground(): void
    {
        unset($_SERVER['__background.test']);

        $background = new BackgroundQueue;
        $background->setContainer($this->getContainer());
        $background->setConnectionName('background');

        run(fn () => $background->pushRaw(json_encode([
            'uuid' => 'raw-job',
            'job' => BackgroundQueueTestHandler::class,
            'data' => ['foo' => 'raw'],
        ], JSON_THROW_ON_ERROR)));

        $this->assertInstanceOf(SyncJob::class, $_SERVER['__background.test'][0]);
        $this->assertSame(['foo' => 'raw'], $_SERVER['__background.test'][1]);
    }

    public function testJobsReportTheirResolvedQueueName(): void
    {
        $background = new BackgroundQueue;
        $background->setConnectionName('background-connection');
        $container = $this->getContainer();
        $events = new EventsDispatcher($container);
        $observed = [];

        $events->listen(JobProcessing::class, static function (JobProcessing $event) use (&$observed): void {
            $observed[] = [$event->connectionName, $event->job->getQueue()];
        });

        $container->instance('events', $events);
        $container->instance(Dispatcher::class, $events);
        $background->setContainer($container);

        foreach ([
            [null, 'background'],
            ['', 'background'],
            ['emails', 'emails'],
            // A queue named "0" is valid and must not be treated as empty.
            ['0', '0'],
        ] as [$queue, $expected]) {
            $observed = [];
            run(fn () => $background->push(BackgroundQueueTestHandler::class, queue: $queue));
            $this->assertSame([['background-connection', $expected]], $observed);
        }
    }

    public function testPushSnapshotsMutableJobBeforeBackgroundExecution(): void
    {
        BackgroundQueueSnapshotHandler::$receivedValue = null;
        $data = (object) ['value' => 'before'];
        $background = new BackgroundQueue;
        $background->setConnectionName('background');
        $background->setContainer($this->getContainer());

        run(function () use ($background, $data): void {
            $background->push(BackgroundQueueSnapshotHandler::class, $data);
            $data->value = 'after';
        });

        $this->assertSame('before', BackgroundQueueSnapshotHandler::$receivedValue);
    }

    public function testPushReportsSerializationFailuresSynchronously(): void
    {
        $exceptionHandled = false;
        $background = new BackgroundQueue;
        $background->setExceptionCallback(function () use (&$exceptionHandled): void {
            $exceptionHandled = true;
        });
        $background->setConnectionName('background');
        $background->setContainer($this->getContainer());

        try {
            $background->push(new UnserializableBackgroundQueueJob);
            $this->fail('Expected job serialization to fail.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Failed to serialize job', $exception->getMessage());
        }

        $this->assertFalse($exceptionHandled);
    }

    public function testFailedJobGetsHandledWhenAnExceptionIsThrown()
    {
        unset($_SERVER['__background.failed']);

        $result = null;

        $background = new BackgroundQueue;
        $background->setExceptionCallback(function ($exception) use (&$result) {
            $result = $exception;
        });
        $background->setConnectionName('background');
        $container = $this->getContainer();
        $events = m::mock(Dispatcher::class);
        $events->shouldReceive('hasListeners')->once()->with(JobProcessing::class)->andReturnTrue();
        $events->shouldReceive('hasListeners')->once()->with(JobExceptionOccurred::class)->andReturnTrue();
        $events->shouldReceive('hasListeners')->once()->with(JobFailed::class)->andReturnTrue();
        $events->shouldReceive('hasListeners')->once()->with(JobAttempted::class)->andReturnTrue();
        $events->shouldReceive('dispatch')->times(4);
        $container->instance('events', $events);
        $container->instance(Dispatcher::class, $events);
        $background->setContainer($container);

        run(function () use ($background) {
            $background->push(FailingBackgroundQueueTestHandler::class, ['foo' => 'bar']);
        });

        $this->assertInstanceOf(Exception::class, $result);
        $this->assertTrue($_SERVER['__background.failed']);
    }

    public function testCancellationDoesNotInvokeBackgroundExceptionCallback(): void
    {
        CancelingBackgroundQueueTestHandler::$failed = false;
        $result = null;
        $background = new BackgroundQueue;
        $background->setExceptionCallback(static function (Throwable $exception) use (&$result): void {
            $result = $exception;
        });
        $background->setConnectionName('background');
        $container = $this->getContainer();
        $events = m::mock(Dispatcher::class);
        $events->shouldReceive('hasListeners')->once()->with(JobProcessing::class)->andReturnTrue();
        $events->shouldReceive('dispatch')->once();
        $container->instance('events', $events);
        $container->instance(Dispatcher::class, $events);
        $background->setContainer($container);

        try {
            run(fn () => $background->push(CancelingBackgroundQueueTestHandler::class));

            $this->assertNull($result);
            $this->assertFalse(CancelingBackgroundQueueTestHandler::$failed);
        } finally {
            CancelingBackgroundQueueTestHandler::$failed = false;
        }
    }

    public function testItAddsATransactionCallbackForAfterCommitJobs()
    {
        $background = new BackgroundQueue;
        $background->setConnectionName('background');
        $container = $this->getContainer();
        $transactionManager = m::mock(DatabaseTransactionsManager::class);
        $transactionManager->shouldReceive('addCallback')->once()->andReturn(null);
        $container->instance('db.transactions', $transactionManager);

        $background->setContainer($container);
        run(fn () => $background->push(new BackgroundQueueAfterCommitJob));
    }

    public function testItAddsATransactionCallbackForInterfaceBasedAfterCommitJobs()
    {
        $background = new BackgroundQueue;
        $background->setConnectionName('background');
        $container = $this->getContainer();
        $transactionManager = m::mock(DatabaseTransactionsManager::class);
        $transactionManager->shouldReceive('addCallback')->once()->andReturn(null);
        $container->instance('db.transactions', $transactionManager);

        $background->setContainer($container);
        run(fn () => $background->push(new BackgroundQueueAfterCommitInterfaceJob));
    }

    public function testItAddsATransactionCallbackForAfterCommitUniqueJobs()
    {
        $background = new BackgroundQueue;
        $background->setConnectionName('background');
        $container = $this->getContainer();
        $transactionManager = m::mock(DatabaseTransactionsManager::class);
        $transactionManager->shouldReceive('addCallback')->once()->andReturn(null);
        $transactionManager->shouldReceive('addCallbackForRollback')->once()->andReturn(null);
        $container->instance('db.transactions', $transactionManager);

        $job = new BackgroundQueueAfterCommitUniqueJob;
        DispatchLockContext::registerUnique($job, $container->make(Cache::class), null, 'unique-key', 'owner');

        $background->setContainer($container);
        run(fn () => $background->push($job));
    }

    public function testItAddsATransactionRollbackCallbackForAfterCommitDebouncedJobs(): void
    {
        $background = new BackgroundQueue;
        $background->setConnectionName('background');
        $container = $this->getContainer();
        $transactionManager = m::mock(DatabaseTransactionsManager::class);
        $transactionManager->shouldReceive('addCallback')->once()->andReturn(null);
        $transactionManager->shouldReceive('addCallbackForRollback')->once()->andReturn(null);
        $container->instance('db.transactions', $transactionManager);

        $job = new BackgroundQueueAfterCommitDebouncedJob;
        DispatchLockContext::registerDebounce($job, $container->make(Cache::class), 'debounce-key', 'owner');

        $background->setContainer($container);
        run(fn () => $background->push($job));
    }

    public function testItAddsATransactionCallbackForInterfaceBasedAfterCommitUniqueJobs()
    {
        $background = new BackgroundQueue;
        $background->setConnectionName('background');
        $container = $this->getContainer();
        $transactionManager = m::mock(DatabaseTransactionsManager::class);
        $transactionManager->shouldReceive('addCallback')->once()->andReturn(null);
        $transactionManager->shouldReceive('addCallbackForRollback')->once()->andReturn(null);
        $container->instance('db.transactions', $transactionManager);

        $job = new BackgroundQueueAfterCommitInterfaceUniqueJob;
        DispatchLockContext::registerUnique($job, $container->make(Cache::class), null, 'unique-key', 'owner');

        $background->setContainer($container);
        run(fn () => $background->push($job));
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

        $background = new BackgroundQueue(timer: $timer);
        $background->setConnectionName('background');
        $background->setContainer($this->getContainer());

        unset($_SERVER['__background.later.test']);

        run(fn () => $background->later(5, BackgroundQueueLaterTestHandler::class, ['foo' => 'bar']));

        $this->assertInstanceOf(SyncJob::class, $_SERVER['__background.later.test'][0]);
        $this->assertEquals(['foo' => 'bar'], $_SERVER['__background.later.test'][1]);
    }

    public function testBulkRespectsDelayAttribute(): void
    {
        $timer = m::mock(Timer::class);
        $timer->shouldReceive('after')
            ->once()
            ->with(5.0, m::type('Closure'))
            ->andReturn(1);

        $background = new BackgroundQueue(timer: $timer);
        $background->setConnectionName('background');
        $background->setContainer($this->getContainer());

        $this->assertNull($background->bulk([new BackgroundQueueBulkDelayJob]));
    }

    public function testLaterSnapshotsMutableJobBeforeTheTimerRuns(): void
    {
        $scheduled = null;
        $timer = m::mock(Timer::class);
        $timer->shouldReceive('after')
            ->once()
            ->with(5.0, m::type('Closure'))
            ->andReturnUsing(function ($delay, $callback) use (&$scheduled) {
                $scheduled = $callback;

                return 1;
            });

        BackgroundQueueSnapshotHandler::$receivedValue = null;
        $data = (object) ['value' => 'before'];
        $background = new BackgroundQueue(timer: $timer);
        $background->setConnectionName('background');
        $background->setContainer($this->getContainer());

        run(function () use ($background, $data, &$scheduled): void {
            $background->later(5, BackgroundQueueSnapshotHandler::class, $data);
            $data->value = 'after';
            $scheduled();
        });

        $this->assertSame('before', BackgroundQueueSnapshotHandler::$receivedValue);
    }

    public function testPushSnapshotsAfterCommitJobWhenTheTransactionCommits(): void
    {
        $afterCommit = null;
        $container = $this->getContainer();
        $transactionManager = m::mock(DatabaseTransactionsManager::class);
        $transactionManager->shouldReceive('addCallback')
            ->once()
            ->andReturnUsing(function ($callback) use (&$afterCommit) {
                $afterCommit = $callback;

                return null;
            });
        $container->instance('db.transactions', $transactionManager);

        BackgroundQueueSnapshotHandler::$receivedValue = null;
        $data = (object) ['value' => 'before'];
        $background = new BackgroundQueue(dispatchAfterCommit: true);
        $background->setConnectionName('background');
        $background->setContainer($container);

        run(function () use ($background, $data, &$afterCommit): void {
            $background->push(BackgroundQueueSnapshotHandler::class, $data);
            $data->value = 'at-commit';
            $afterCommit();
            $data->value = 'after';
        });

        $this->assertSame('at-commit', BackgroundQueueSnapshotHandler::$receivedValue);
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

        $background = new BackgroundQueue(timer: $timer);
        $background->setConnectionName('background');
        $background->setContainer($this->getContainer());

        unset($_SERVER['__background.later.test']);

        run(fn () => $background->later(new DateInterval('PT10S'), BackgroundQueueLaterTestHandler::class, ['baz' => 'qux']));

        $this->assertInstanceOf(SyncJob::class, $_SERVER['__background.later.test'][0]);
        $this->assertEquals(['baz' => 'qux'], $_SERVER['__background.later.test'][1]);

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

        $background = new BackgroundQueue(timer: $timer);
        $background->setConnectionName('background');
        $background->setContainer($this->getContainer());

        unset($_SERVER['__background.later.test']);

        run(fn () => $background->later(CarbonImmutable::parse('2024-01-01 12:00:15'), BackgroundQueueLaterTestHandler::class, ['test' => 'data']));

        $this->assertInstanceOf(SyncJob::class, $_SERVER['__background.later.test'][0]);
        $this->assertEquals(['test' => 'data'], $_SERVER['__background.later.test'][1]);

        CarbonImmutable::setTestNow();
    }

    public function testLaterAddsTransactionCallbackForAfterCommitJobs()
    {
        $timer = m::mock(Timer::class);
        $timer->shouldReceive('after')->once()->with(5.0, m::type('Closure'))->andReturn(1);

        $background = new BackgroundQueue(timer: $timer);
        $background->setConnectionName('background');

        $container = $this->getContainer();
        $transactionManager = m::mock(DatabaseTransactionsManager::class);
        $transactionManager->shouldReceive('addCallback')
            ->once()
            ->andReturnUsing(function ($callback) {
                $callback();
                return null;
            });
        $container->instance('db.transactions', $transactionManager);
        $background->setContainer($container);

        run(fn () => $background->later(5, new BackgroundQueueAfterCommitJob));
    }

    public function testLaterAddsTransactionCallbackForInterfaceBasedAfterCommitJobs()
    {
        $timer = m::mock(Timer::class);
        $timer->shouldReceive('after')->once()->with(5.0, m::type('Closure'))->andReturn(1);

        $background = new BackgroundQueue(timer: $timer);
        $background->setConnectionName('background');

        $container = $this->getContainer();
        $transactionManager = m::mock(DatabaseTransactionsManager::class);
        $transactionManager->shouldReceive('addCallback')
            ->once()
            ->andReturnUsing(function ($callback) {
                $callback();
                return null;
            });
        $container->instance('db.transactions', $transactionManager);
        $background->setContainer($container);

        run(fn () => $background->later(5, new BackgroundQueueAfterCommitInterfaceJob));
    }

    public function testLaterAddsTransactionCallbackForAfterCommitUniqueJobs()
    {
        $timer = m::mock(Timer::class);
        $timer->shouldReceive('after')->once()->with(5.0, m::type('Closure'))->andReturn(1);

        $background = new BackgroundQueue(timer: $timer);
        $background->setConnectionName('background');

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
        $job = new BackgroundQueueAfterCommitUniqueJob;
        DispatchLockContext::registerUnique($job, $container->make(Cache::class), null, 'unique-key', 'owner');
        $background->setContainer($container);

        run(fn () => $background->later(5, $job));
    }

    public function testLaterAddsTransactionRollbackCallbackForAfterCommitDebouncedJobs(): void
    {
        $timer = m::mock(Timer::class);
        $timer->shouldReceive('after')->once()->with(5.0, m::type('Closure'))->andReturn(1);

        $background = new BackgroundQueue(timer: $timer);
        $background->setConnectionName('background');

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
        $job = new BackgroundQueueAfterCommitDebouncedJob;
        DispatchLockContext::registerDebounce($job, $container->make(Cache::class), 'debounce-key', 'owner');
        $background->setContainer($container);

        run(fn () => $background->later(5, $job));
    }

    public function testLaterAddsTransactionCallbackForInterfaceBasedAfterCommitUniqueJobs()
    {
        $timer = m::mock(Timer::class);
        $timer->shouldReceive('after')->once()->with(5.0, m::type('Closure'))->andReturn(1);

        $background = new BackgroundQueue(timer: $timer);
        $background->setConnectionName('background');

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
        $job = new BackgroundQueueAfterCommitInterfaceUniqueJob;
        DispatchLockContext::registerUnique($job, $container->make(Cache::class), null, 'unique-key', 'owner');
        $background->setContainer($container);

        run(fn () => $background->later(5, $job));
    }

    public function testLaterClampsNegativeIntegerDelay()
    {
        $timer = m::mock(Timer::class);
        $timer->shouldReceive('after')->once()->with(0.0, m::type('Closure'))->andReturn(1);

        $background = new BackgroundQueue(timer: $timer);
        $background->setConnectionName('background');
        $background->setContainer($this->getContainer());

        run(fn () => $background->later(-5, BackgroundQueueLaterTestHandler::class));
    }

    public function testLaterClampsPastDateTimeInterface(): void
    {
        CarbonImmutable::setTestNow('2024-01-01 12:00:00');

        $timer = m::mock(Timer::class);
        $timer->shouldReceive('after')->once()->with(0.0, m::type('Closure'))->andReturn(1);

        $background = new BackgroundQueue(timer: $timer);
        $background->setConnectionName('background');
        $background->setContainer($this->getContainer());

        run(fn () => $background->later(CarbonImmutable::parse('2024-01-01 11:59:50'), BackgroundQueueLaterTestHandler::class));

        CarbonImmutable::setTestNow();
    }

    public function testLaterFailedJobGetsHandledWhenAnExceptionIsThrown()
    {
        unset($_SERVER['__background.failed']);

        $result = null;

        $timer = m::mock(Timer::class);
        $timer->shouldReceive('after')
            ->once()
            ->with(5.0, m::type('Closure'))
            ->andReturnUsing(function ($delay, $callback) {
                $callback();
                return 1;
            });

        $background = new BackgroundQueue(timer: $timer);
        $background->setExceptionCallback(function ($exception) use (&$result) {
            $result = $exception;
        });
        $background->setConnectionName('background');
        $container = $this->getContainer();
        $events = m::mock(Dispatcher::class);
        $events->shouldReceive('hasListeners')->once()->with(JobProcessing::class)->andReturnTrue();
        $events->shouldReceive('hasListeners')->once()->with(JobExceptionOccurred::class)->andReturnTrue();
        $events->shouldReceive('hasListeners')->once()->with(JobFailed::class)->andReturnTrue();
        $events->shouldReceive('hasListeners')->once()->with(JobAttempted::class)->andReturnTrue();
        $events->shouldReceive('dispatch')->times(4);
        $container->instance('events', $events);
        $container->instance(Dispatcher::class, $events);
        $background->setContainer($container);

        run(fn () => $background->later(5, FailingBackgroundQueueTestHandler::class, ['foo' => 'bar']));

        $this->assertInstanceOf(Exception::class, $result);
        $this->assertTrue($_SERVER['__background.failed']);
    }

    public function testLaterDoesNotExecuteJobWhenWorkerIsClosing()
    {
        unset($_SERVER['__background.later.test']);

        $cache = new Repository(new WorkerArrayStore);
        $job = new BackgroundQueueAfterCommitUniqueJob;
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

        $background = new BackgroundQueue(timer: $timer);
        $background->setConnectionName('background');
        $background->setContainer($this->getContainer());

        run(fn () => $background->later(5, $job));

        $this->assertArrayNotHasKey('__background.later.test', $_SERVER);
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
}

class BackgroundQueueTestEntity implements QueueableEntity
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

class BackgroundQueueTestHandler
{
    public function fire($job, $data)
    {
        $_SERVER['__background.test'] = func_get_args();
    }
}

class FailingBackgroundQueueTestHandler
{
    public function fire($job, $data)
    {
        throw new Exception;
    }

    public function failed()
    {
        $_SERVER['__background.failed'] = true;
    }
}

class CancelingBackgroundQueueTestHandler
{
    public static bool $failed = false;

    public function fire(): never
    {
        $gate = new Channel(1);
        $coroutineId = EngineCoroutine::id();

        EngineCoroutine::create(static function () use ($coroutineId, $gate): void {
            $gate->pop();
            EngineCoroutine::cancelById($coroutineId, throwException: true);
        });

        $gate->push(true);

        throw new RuntimeException('Cancellation was not delivered.');
    }

    public function failed(): void
    {
        static::$failed = true;
    }
}

class BackgroundQueueAfterCommitJob
{
    use InteractsWithQueue;

    public $afterCommit = true;

    public function handle()
    {
    }
}

class BackgroundQueueAfterCommitInterfaceJob implements ShouldQueueAfterCommit
{
    use InteractsWithQueue;

    public function handle()
    {
    }
}

class BackgroundQueueAfterCommitUniqueJob implements ShouldBeUnique
{
    use InteractsWithQueue;

    public $afterCommit = true;

    public function handle(): void
    {
    }
}

class BackgroundQueueAfterCommitDebouncedJob
{
    use InteractsWithQueue;

    public bool $afterCommit = true;

    public string $debounceOwner = 'owner-token';

    public function handle(): void
    {
    }
}

class BackgroundQueueAfterCommitInterfaceUniqueJob implements ShouldBeUnique, ShouldQueueAfterCommit
{
    use InteractsWithQueue;

    public function handle(): void
    {
    }
}

class BackgroundQueueLaterTestHandler
{
    public function fire(SyncJob $job, mixed $data): void
    {
        $_SERVER['__background.later.test'] = func_get_args();
    }
}

#[Delay(5)]
class BackgroundQueueBulkDelayJob
{
}

class BackgroundQueueSnapshotHandler
{
    public static ?string $receivedValue = null;

    public function fire(SyncJob $job, array $data): void
    {
        static::$receivedValue = $data['value'];
    }
}

class UnserializableBackgroundQueueJob
{
    public function __construct(public mixed $callback = null)
    {
        $this->callback = fn () => null;
    }

    public function handle(): void
    {
    }
}
