<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue;

use DateInterval;
use Exception;
use Hyperf\Coordinator\Timer;
use Hyperf\Di\Container;
use Hyperf\Di\Definition\DefinitionSource;
use Hypervel\Database\TransactionManager;
use Hypervel\Queue\Contracts\QueueableEntity;
use Hypervel\Queue\Contracts\ShouldQueueAfterCommit;
use Hypervel\Queue\DeferQueue;
use Hypervel\Queue\InteractsWithQueue;
use Hypervel\Queue\Jobs\SyncJob;
use Hypervel\Support\Carbon;
use Mockery as m;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;

use function Hyperf\Coroutine\run;

/**
 * @internal
 * @coversNothing
 */
class QueueDeferQueueTest extends TestCase
{
    public function testPushShouldDefer()
    {
        unset($_SERVER['__defer.test']);

        $defer = new DeferQueue();
        $defer->setConnectionName('defer');
        $container = $this->getContainer();
        $defer->setContainer($container);
        $defer->setConnectionName('defer');

        run(fn () => $defer->push(DeferQueueTestHandler::class, ['foo' => 'bar']));

        $this->assertInstanceOf(SyncJob::class, $_SERVER['__defer.test'][0]);
        $this->assertEquals(['foo' => 'bar'], $_SERVER['__defer.test'][1]);
    }

    public function testFailedJobGetsHandledWhenAnExceptionIsThrown()
    {
        unset($_SERVER['__defer.failed']);

        $result = null;

        $defer = new DeferQueue();
        $defer->setExceptionCallback(function ($exception) use (&$result) {
            $result = $exception;
        });
        $defer->setConnectionName('defer');
        $container = $this->getContainer();
        $events = m::mock(EventDispatcherInterface::class);
        $events->shouldReceive('dispatch')->times(3);
        $container->set(EventDispatcherInterface::class, $events);
        $defer->setContainer($container);

        run(function () use ($defer) {
            $defer->push(FailingDeferQueueTestHandler::class, ['foo' => 'bar']);
        });

        $this->assertInstanceOf(Exception::class, $result);
        $this->assertTrue($_SERVER['__defer.failed']);
    }

    public function testItAddsATransactionCallbackForAfterCommitJobs()
    {
        $defer = new DeferQueue();
        $container = $this->getContainer();
        $transactionManager = m::mock(TransactionManager::class);
        $transactionManager->shouldReceive('addCallback')->once()->andReturn(null);
        $container->set(TransactionManager::class, $transactionManager);

        $defer->setContainer($container);
        run(fn () => $defer->push(new DeferQueueAfterCommitJob()));
    }

    public function testItAddsATransactionCallbackForInterfaceBasedAfterCommitJobs()
    {
        $defer = new DeferQueue();
        $container = $this->getContainer();
        $transactionManager = m::mock(TransactionManager::class);
        $transactionManager->shouldReceive('addCallback')->once()->andReturn(null);
        $container->set(TransactionManager::class, $transactionManager);

        $defer->setContainer($container);
        run(fn () => $defer->push(new DeferQueueAfterCommitInterfaceJob()));
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

        $defer = new DeferQueue(timer: $timer);
        $defer->setConnectionName('defer');
        $container = $this->getContainer();
        $defer->setContainer($container);

        unset($_SERVER['__defer.later.test']);

        run(fn () => $defer->later(5, DeferQueueLaterTestHandler::class, ['foo' => 'bar']));

        $this->assertInstanceOf(SyncJob::class, $_SERVER['__defer.later.test'][0]);
        $this->assertEquals(['foo' => 'bar'], $_SERVER['__defer.later.test'][1]);
    }

    public function testLaterWithDateInterval()
    {
        $timer = m::mock(Timer::class);
        $interval = new DateInterval('PT10S');

        $timer->shouldReceive('after')
            ->once()
            ->with(10.0, m::type('Closure'))
            ->andReturnUsing(function ($delay, $callback) {
                $callback();
                return 1;
            });

        $defer = new DeferQueue(timer: $timer);
        $defer->setConnectionName('defer');
        $container = $this->getContainer();
        $defer->setContainer($container);

        unset($_SERVER['__defer.later.interval.test']);

        run(fn () => $defer->later($interval, DeferQueueLaterIntervalTestHandler::class, ['baz' => 'qux']));

        $this->assertInstanceOf(SyncJob::class, $_SERVER['__defer.later.interval.test'][0]);
        $this->assertEquals(['baz' => 'qux'], $_SERVER['__defer.later.interval.test'][1]);
    }

    public function testLaterWithDateTime()
    {
        Carbon::setTestNow('2024-01-01 12:00:00');

        $timer = m::mock(Timer::class);
        $dateTime = Carbon::parse('2024-01-01 12:00:15');

        $timer->shouldReceive('after')
            ->once()
            ->with(15.0, m::type('Closure'))
            ->andReturnUsing(function ($delay, $callback) {
                $callback();
                return 1;
            });

        $defer = new DeferQueue(timer: $timer);
        $defer->setConnectionName('defer');
        $container = $this->getContainer();
        $defer->setContainer($container);

        unset($_SERVER['__defer.later.datetime.test']);

        run(fn () => $defer->later($dateTime, DeferQueueLaterDateTimeTestHandler::class, ['test' => 'data']));

        $this->assertInstanceOf(SyncJob::class, $_SERVER['__defer.later.datetime.test'][0]);
        $this->assertEquals(['test' => 'data'], $_SERVER['__defer.later.datetime.test'][1]);

        Carbon::setTestNow();
    }

    protected function getContainer(): Container
    {
        return new Container(
            new DefinitionSource([])
        );
    }
}

class DeferQueueTestEntity implements QueueableEntity
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

class DeferQueueTestHandler
{
    public function fire($job, $data)
    {
        $_SERVER['__defer.test'] = func_get_args();
    }
}

class FailingDeferQueueTestHandler
{
    public function fire($job, $data)
    {
        throw new Exception();
    }

    public function failed()
    {
        $_SERVER['__defer.failed'] = true;
    }
}

class DeferQueueAfterCommitJob
{
    use InteractsWithQueue;

    public $afterCommit = true;

    public function handle()
    {
    }
}

class DeferQueueAfterCommitInterfaceJob implements ShouldQueueAfterCommit
{
    use InteractsWithQueue;

    public function handle()
    {
    }
}

class DeferQueueLaterTestHandler
{
    public function fire($job, $data)
    {
        $_SERVER['__defer.later.test'] = func_get_args();
    }
}

class DeferQueueLaterIntervalTestHandler
{
    public function fire($job, $data)
    {
        $_SERVER['__defer.later.interval.test'] = func_get_args();
    }
}

class DeferQueueLaterDateTimeTestHandler
{
    public function fire($job, $data)
    {
        $_SERVER['__defer.later.datetime.test'] = func_get_args();
    }
}
