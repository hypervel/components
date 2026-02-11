<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue;

use Exception;
use Hypervel\Container\Container;
use Hypervel\Contracts\Queue\QueueableEntity;
use Hypervel\Contracts\Queue\ShouldQueueAfterCommit;
use Hypervel\Database\DatabaseTransactionsManager;
use Hypervel\Queue\CoroutineQueue;
use Hypervel\Queue\InteractsWithQueue;
use Hypervel\Queue\Jobs\SyncJob;
use Mockery as m;
use PHPUnit\Framework\TestCase;
use Hypervel\Contracts\Event\Dispatcher;

use function Hypervel\Coroutine\run;

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
        $events = m::mock(Dispatcher::class);
        $events->shouldReceive('dispatch')->times(3);
        $container->instance(Dispatcher::class, $events);
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
        $coroutine->setConnectionName('coroutine');
        $container = $this->getContainer();
        $transactionManager = m::mock(DatabaseTransactionsManager::class);
        $transactionManager->shouldReceive('addCallback')->once()->andReturn(null);
        $container->instance('db.transactions', $transactionManager);

        $coroutine->setContainer($container);
        run(fn () => $coroutine->push(new CoroutineQueueAfterCommitJob()));
    }

    public function testItAddsATransactionCallbackForInterfaceBasedAfterCommitJobs()
    {
        $coroutine = new CoroutineQueue();
        $coroutine->setConnectionName('coroutine');
        $container = $this->getContainer();
        $transactionManager = m::mock(DatabaseTransactionsManager::class);
        $transactionManager->shouldReceive('addCallback')->once()->andReturn(null);
        $container->instance('db.transactions', $transactionManager);

        $coroutine->setContainer($container);
        run(fn () => $coroutine->push(new CoroutineQueueAfterCommitInterfaceJob()));
    }

    protected function getContainer(): Container
    {
        return new Container();
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
