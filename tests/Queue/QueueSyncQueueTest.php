<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue;

use Exception;
use Hypervel\Bus\Dispatcher as BusDispatcher;
use Hypervel\Container\Container;
use Hypervel\Contracts\Bus\Dispatcher;
use Hypervel\Contracts\Bus\Dispatcher as DispatcherContract;
use Hypervel\Contracts\Container\Container as ContainerContract;
use Hypervel\Contracts\Events\Dispatcher as EventDispatcher;
use Hypervel\Contracts\Queue\QueueableEntity;
use Hypervel\Contracts\Queue\ShouldBeUnique;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Contracts\Queue\ShouldQueueAfterCommit;
use Hypervel\Database\DatabaseTransactionsManager;
use Hypervel\Events\Dispatcher as EventsDispatcher;
use Hypervel\Queue\CallQueuedHandler;
use Hypervel\Queue\Events\JobAttempted;
use Hypervel\Queue\Events\JobProcessing;
use Hypervel\Queue\InteractsWithQueue;
use Hypervel\Queue\Jobs\SyncJob;
use Hypervel\Queue\SyncQueue;
use Hypervel\Tests\TestCase;
use LogicException;
use Mockery as m;
use RuntimeException;

class QueueSyncQueueTest extends TestCase
{
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

    public function testFailedJobGetsHandledWhenAnExceptionIsThrown()
    {
        unset($_SERVER['__sync.failed']);

        $sync = new SyncQueue;
        $sync->setConnectionName('sync');
        $container = $this->getContainer();
        $events = m::mock(EventDispatcher::class);
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
        $events = m::mock(EventDispatcher::class);
        $events->shouldReceive('dispatch');
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

    protected function getContainer(): Container
    {
        $container = new Container;
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
