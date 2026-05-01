<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue;

use DateInterval;
use Exception;
use Hypervel\Container\Container;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Queue\QueueableEntity;
use Hypervel\Contracts\Queue\ShouldBeUnique;
use Hypervel\Contracts\Queue\ShouldQueueAfterCommit;
use Hypervel\Coordinator\Timer;
use Hypervel\Database\DatabaseTransactionsManager;
use Hypervel\Queue\DeferredQueue;
use Hypervel\Queue\InteractsWithQueue;
use Hypervel\Queue\Jobs\SyncJob;
use Hypervel\Support\Carbon;
use Hypervel\Tests\TestCase;
use Mockery as m;

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

        $deferred->setContainer($container);
        run(fn () => $deferred->push(new DeferredQueueAfterCommitUniqueJob));
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

        $deferred->setContainer($container);
        run(fn () => $deferred->push(new DeferredQueueAfterCommitInterfaceUniqueJob));
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
    }

    public function testLaterWithDateTime()
    {
        Carbon::setTestNow('2024-01-01 12:00:00');

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

        run(fn () => $deferred->later(Carbon::parse('2024-01-01 12:00:15'), DeferredQueueLaterTestHandler::class, ['test' => 'data']));

        $this->assertInstanceOf(SyncJob::class, $_SERVER['__deferred.later.test'][0]);
        $this->assertEquals(['test' => 'data'], $_SERVER['__deferred.later.test'][1]);

        Carbon::setTestNow();
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
        $deferred->setContainer($container);

        run(fn () => $deferred->later(5, new DeferredQueueAfterCommitUniqueJob));
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
        $deferred->setContainer($container);

        run(fn () => $deferred->later(5, new DeferredQueueAfterCommitInterfaceUniqueJob));
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

    public function testLaterClampsPastDateTimeInterface()
    {
        Carbon::setTestNow('2024-01-01 12:00:00');

        $timer = m::mock(Timer::class);
        $timer->shouldReceive('after')->once()->with(0.0, m::type('Closure'))->andReturn(1);

        $deferred = new DeferredQueue(timer: $timer);
        $deferred->setConnectionName('deferred');
        $deferred->setContainer($this->getContainer());

        run(fn () => $deferred->later(Carbon::parse('2024-01-01 11:59:50'), DeferredQueueLaterTestHandler::class));

        Carbon::setTestNow();
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

        $timer = m::mock(Timer::class);
        $timer->shouldReceive('after')
            ->once()
            ->with(5.0, m::type('Closure'))
            ->andReturnUsing(function ($delay, $callback) {
                $callback(true);
                return 1;
            });

        $deferred = new DeferredQueue(timer: $timer);
        $deferred->setConnectionName('deferred');
        $deferred->setContainer($this->getContainer());

        run(fn () => $deferred->later(5, DeferredQueueLaterTestHandler::class, ['foo' => 'bar']));

        $this->assertArrayNotHasKey('__deferred.later.test', $_SERVER);
    }

    protected function getContainer(): Container
    {
        return new Container;
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
