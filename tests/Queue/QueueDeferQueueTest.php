<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue;

use Exception;
use Hypervel\Container\Container;
use Hypervel\Contracts\Queue\QueueableEntity;
use Hypervel\Contracts\Queue\ShouldQueueAfterCommit;
use Hypervel\Database\DatabaseTransactionsManager;
use Hypervel\Queue\DeferQueue;
use Hypervel\Queue\InteractsWithQueue;
use Hypervel\Queue\Jobs\SyncJob;
use Mockery as m;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;

use function Hypervel\Coroutine\run;

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
        $container->instance(EventDispatcherInterface::class, $events);
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
        $defer->setConnectionName('defer');
        $container = $this->getContainer();
        $transactionManager = m::mock(DatabaseTransactionsManager::class);
        $transactionManager->shouldReceive('addCallback')->once()->andReturn(null);
        $container->instance('db.transactions', $transactionManager);

        $defer->setContainer($container);
        run(fn () => $defer->push(new DeferQueueAfterCommitJob()));
    }

    public function testItAddsATransactionCallbackForInterfaceBasedAfterCommitJobs()
    {
        $defer = new DeferQueue();
        $defer->setConnectionName('defer');
        $container = $this->getContainer();
        $transactionManager = m::mock(DatabaseTransactionsManager::class);
        $transactionManager->shouldReceive('addCallback')->once()->andReturn(null);
        $container->instance('db.transactions', $transactionManager);

        $defer->setContainer($container);
        run(fn () => $defer->push(new DeferQueueAfterCommitInterfaceJob()));
    }

    protected function getContainer(): Container
    {
        return new Container();
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
