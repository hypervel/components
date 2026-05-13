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
use Hypervel\Queue\BackgroundQueue;
use Hypervel\Queue\InteractsWithQueue;
use Hypervel\Queue\Jobs\SyncJob;
use Hypervel\Support\Carbon;
use Hypervel\Tests\TestCase;
use Mockery as m;

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

        $background->setContainer($container);
        run(fn () => $background->push(new BackgroundQueueAfterCommitUniqueJob));
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

        $background->setContainer($container);
        run(fn () => $background->push(new BackgroundQueueAfterCommitInterfaceUniqueJob));
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

        $background = new BackgroundQueue(timer: $timer);
        $background->setConnectionName('background');
        $background->setContainer($this->getContainer());

        unset($_SERVER['__background.later.test']);

        run(fn () => $background->later(new DateInterval('PT10S'), BackgroundQueueLaterTestHandler::class, ['baz' => 'qux']));

        $this->assertInstanceOf(SyncJob::class, $_SERVER['__background.later.test'][0]);
        $this->assertEquals(['baz' => 'qux'], $_SERVER['__background.later.test'][1]);
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

        $background = new BackgroundQueue(timer: $timer);
        $background->setConnectionName('background');
        $background->setContainer($this->getContainer());

        unset($_SERVER['__background.later.test']);

        run(fn () => $background->later(Carbon::parse('2024-01-01 12:00:15'), BackgroundQueueLaterTestHandler::class, ['test' => 'data']));

        $this->assertInstanceOf(SyncJob::class, $_SERVER['__background.later.test'][0]);
        $this->assertEquals(['test' => 'data'], $_SERVER['__background.later.test'][1]);

        Carbon::setTestNow();
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
        $background->setContainer($container);

        run(fn () => $background->later(5, new BackgroundQueueAfterCommitUniqueJob));
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
        $background->setContainer($container);

        run(fn () => $background->later(5, new BackgroundQueueAfterCommitInterfaceUniqueJob));
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

    public function testLaterClampsPastDateTimeInterface()
    {
        Carbon::setTestNow('2024-01-01 12:00:00');

        $timer = m::mock(Timer::class);
        $timer->shouldReceive('after')->once()->with(0.0, m::type('Closure'))->andReturn(1);

        $background = new BackgroundQueue(timer: $timer);
        $background->setConnectionName('background');
        $background->setContainer($this->getContainer());

        run(fn () => $background->later(Carbon::parse('2024-01-01 11:59:50'), BackgroundQueueLaterTestHandler::class));

        Carbon::setTestNow();
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

        $timer = m::mock(Timer::class);
        $timer->shouldReceive('after')
            ->once()
            ->with(5.0, m::type('Closure'))
            ->andReturnUsing(function ($delay, $callback) {
                $callback(true);
                return 1;
            });

        $background = new BackgroundQueue(timer: $timer);
        $background->setConnectionName('background');
        $background->setContainer($this->getContainer());

        run(fn () => $background->later(5, BackgroundQueueLaterTestHandler::class, ['foo' => 'bar']));

        $this->assertArrayNotHasKey('__background.later.test', $_SERVER);
    }

    protected function getContainer(): Container
    {
        return new Container;
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
