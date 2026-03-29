<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue;

use Exception;
use Hypervel\Container\Container;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Queue\QueueableEntity;
use Hypervel\Contracts\Queue\ShouldQueueAfterCommit;
use Hypervel\Database\DatabaseTransactionsManager;
use Hypervel\Queue\DeferredQueue;
use Hypervel\Queue\InteractsWithQueue;
use Hypervel\Queue\Jobs\SyncJob;
use Hypervel\Tests\TestCase;
use Mockery as m;

use function Hypervel\Coroutine\run;

/**
 * @internal
 * @coversNothing
 */
class QueueDeferredQueueTest extends TestCase
{
    protected bool $runTestsInCoroutine = false;

    public function testPushShouldDefer()
    {
        unset($_SERVER['__deferred.test']);

        $deferred = new DeferredQueue();
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

        $deferred = new DeferredQueue();
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
        $deferred = new DeferredQueue();
        $deferred->setConnectionName('deferred');
        $container = $this->getContainer();
        $transactionManager = m::mock(DatabaseTransactionsManager::class);
        $transactionManager->shouldReceive('addCallback')->once()->andReturn(null);
        $container->instance('db.transactions', $transactionManager);

        $deferred->setContainer($container);
        run(fn () => $deferred->push(new DeferredQueueAfterCommitJob()));
    }

    public function testItAddsATransactionCallbackForInterfaceBasedAfterCommitJobs()
    {
        $deferred = new DeferredQueue();
        $deferred->setConnectionName('deferred');
        $container = $this->getContainer();
        $transactionManager = m::mock(DatabaseTransactionsManager::class);
        $transactionManager->shouldReceive('addCallback')->once()->andReturn(null);
        $container->instance('db.transactions', $transactionManager);

        $deferred->setContainer($container);
        run(fn () => $deferred->push(new DeferredQueueAfterCommitInterfaceJob()));
    }

    protected function getContainer(): Container
    {
        return new Container();
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
        throw new Exception();
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
