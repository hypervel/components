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
use Hypervel\Queue\CoroutineQueue;
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
class QueueCoroutineQueueTest extends TestCase
{
    public function testPushShouldCoroutine()
    {
        unset($_SERVER['__coroutine.test']);

        $coroutine = new CoroutineQueue();
        $coroutine->setConnectionName('coroutine');
        $container = $this->getContainer();
        $coroutine->setContainer($container);
        $coroutine->setConnectionName('coroutine');

        run(fn () => $coroutine->push(CoroutineQueueTestHandler::class, ['foo' => 'bar']));

        $this->assertInstanceOf(SyncJob::class, $_SERVER['__coroutine.test'][0]);
        $this->assertEquals(['foo' => 'bar'], $_SERVER['__coroutine.test'][1]);
    }

    public function testFailedJobGetsHandledWhenAnExceptionIsThrown()
    {
        unset($_SERVER['__coroutine.failed']);

        $result = null;

        $coroutine = new CoroutineQueue();
        $coroutine->setExceptionCallback(function ($exception) use (&$result) {
            $result = $exception;
        });
        $coroutine->setConnectionName('coroutine');
        $container = $this->getContainer();
        $events = m::mock(EventDispatcherInterface::class);
        $events->shouldReceive('dispatch')->times(3);
        $container->set(EventDispatcherInterface::class, $events);
        $coroutine->setContainer($container);

        run(function () use ($coroutine) {
            $coroutine->push(FailingCoroutineQueueTestHandler::class, ['foo' => 'bar']);
        });

        $this->assertInstanceOf(Exception::class, $result);
        $this->assertTrue($_SERVER['__coroutine.failed']);
    }

    public function testItAddsATransactionCallbackForAfterCommitJobs()
    {
        $coroutine = new CoroutineQueue();
        $container = $this->getContainer();
        $transactionManager = m::mock(TransactionManager::class);
        $transactionManager->shouldReceive('addCallback')->once()->andReturn(null);
        $container->set(TransactionManager::class, $transactionManager);

        $coroutine->setContainer($container);
        run(fn () => $coroutine->push(new CoroutineQueueAfterCommitJob()));
    }

    public function testItAddsATransactionCallbackForInterfaceBasedAfterCommitJobs()
    {
        $coroutine = new CoroutineQueue();
        $container = $this->getContainer();
        $transactionManager = m::mock(TransactionManager::class);
        $transactionManager->shouldReceive('addCallback')->once()->andReturn(null);
        $container->set(TransactionManager::class, $transactionManager);

        $coroutine->setContainer($container);
        run(fn () => $coroutine->push(new CoroutineQueueAfterCommitInterfaceJob()));
    }

    public function testLaterSchedulesJobWithDelay()
    {
        $timer = m::mock(Timer::class);
        $timer->shouldReceive('after')
            ->once()
            ->with(5.0, m::type('Closure'))
            ->andReturnUsing(function ($_, $callback) {
                $callback();
                return 1;
            });

        $coroutine = new CoroutineQueue(timer: $timer);
        $coroutine->setConnectionName('coroutine');
        $container = $this->getContainer();
        $coroutine->setContainer($container);

        unset($_SERVER['__coroutine.later.test']);

        run(fn () => $coroutine->later(5, CoroutineQueueLaterTestHandler::class, ['foo' => 'bar']));

        $this->assertInstanceOf(SyncJob::class, $_SERVER['__coroutine.later.test'][0]);
        $this->assertEquals(['foo' => 'bar'], $_SERVER['__coroutine.later.test'][1]);
    }

    public function testLaterWithDateInterval()
    {
        $timer = m::mock(Timer::class);
        $interval = new DateInterval('PT10S');

        $timer->shouldReceive('after')
            ->once()
            ->with(10.0, m::type('Closure'))
            ->andReturnUsing(function ($_, $callback) {
                $callback();
                return 1;
            });

        $coroutine = new CoroutineQueue(timer: $timer);
        $coroutine->setConnectionName('coroutine');
        $container = $this->getContainer();
        $coroutine->setContainer($container);

        unset($_SERVER['__coroutine.later.interval.test']);

        run(fn () => $coroutine->later($interval, CoroutineQueueLaterIntervalTestHandler::class, ['baz' => 'qux']));

        $this->assertInstanceOf(SyncJob::class, $_SERVER['__coroutine.later.interval.test'][0]);
        $this->assertEquals(['baz' => 'qux'], $_SERVER['__coroutine.later.interval.test'][1]);
    }

    public function testLaterWithDateTime()
    {
        Carbon::setTestNow('2024-01-01 12:00:00');

        $timer = m::mock(Timer::class);
        $dateTime = Carbon::parse('2024-01-01 12:00:15');

        $timer->shouldReceive('after')
            ->once()
            ->with(15.0, m::type('Closure'))
            ->andReturnUsing(function ($_, $callback) {
                $callback();
                return 1;
            });

        $coroutine = new CoroutineQueue(timer: $timer);
        $coroutine->setConnectionName('coroutine');
        $container = $this->getContainer();
        $coroutine->setContainer($container);

        unset($_SERVER['__coroutine.later.datetime.test']);

        run(fn () => $coroutine->later($dateTime, CoroutineQueueLaterDateTimeTestHandler::class, ['test' => 'data']));

        $this->assertInstanceOf(SyncJob::class, $_SERVER['__coroutine.later.datetime.test'][0]);
        $this->assertEquals(['test' => 'data'], $_SERVER['__coroutine.later.datetime.test'][1]);

        Carbon::setTestNow();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        Carbon::setTestNow();
        m::close();
    }

    protected function getContainer(): Container
    {
        return new Container(
            new DefinitionSource([])
        );
    }
}

class CoroutineQueueTestEntity implements QueueableEntity
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

class CoroutineQueueTestHandler
{
    public function fire($job, $data)
    {
        $_SERVER['__coroutine.test'] = func_get_args();
    }
}

class FailingCoroutineQueueTestHandler
{
    public function fire($job, $data)
    {
        throw new Exception();
    }

    public function failed()
    {
        $_SERVER['__coroutine.failed'] = true;
    }
}

class CoroutineQueueAfterCommitJob
{
    use InteractsWithQueue;

    public $afterCommit = true;

    public function handle()
    {
    }
}

class CoroutineQueueAfterCommitInterfaceJob implements ShouldQueueAfterCommit
{
    use InteractsWithQueue;

    public function handle()
    {
    }
}

class CoroutineQueueLaterTestHandler
{
    public function fire($job, $data)
    {
        $_SERVER['__coroutine.later.test'] = func_get_args();
    }
}

class CoroutineQueueLaterIntervalTestHandler
{
    public function fire($job, $data)
    {
        $_SERVER['__coroutine.later.interval.test'] = func_get_args();
    }
}

class CoroutineQueueLaterDateTimeTestHandler
{
    public function fire($job, $data)
    {
        $_SERVER['__coroutine.later.datetime.test'] = func_get_args();
    }
}
