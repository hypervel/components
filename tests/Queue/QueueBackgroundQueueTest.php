<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue;

use Exception;
use Hypervel\Container\Container;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Queue\QueueableEntity;
use Hypervel\Contracts\Queue\ShouldQueueAfterCommit;
use Hypervel\Database\DatabaseTransactionsManager;
use Hypervel\Queue\BackgroundQueue;
use Hypervel\Queue\InteractsWithQueue;
use Hypervel\Queue\Jobs\SyncJob;
use Hypervel\Tests\TestCase;
use Mockery as m;

use function Hypervel\Coroutine\run;

/**
 * @internal
 * @coversNothing
 */
class QueueBackgroundQueueTest extends TestCase
{
    protected bool $runTestsInCoroutine = false;

    public function testPushShouldRunInBackground()
    {
        unset($_SERVER['__background.test']);

        $background = new BackgroundQueue();
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

        $background = new BackgroundQueue();
        $background->setExceptionCallback(function ($exception) use (&$result) {
            $result = $exception;
        });
        $background->setConnectionName('background');
        $container = $this->getContainer();
        $events = m::mock(Dispatcher::class);
        $events->shouldReceive('dispatch')->times(4);
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
        $background = new BackgroundQueue();
        $background->setConnectionName('background');
        $container = $this->getContainer();
        $transactionManager = m::mock(DatabaseTransactionsManager::class);
        $transactionManager->shouldReceive('addCallback')->once()->andReturn(null);
        $container->instance('db.transactions', $transactionManager);

        $background->setContainer($container);
        run(fn () => $background->push(new BackgroundQueueAfterCommitJob()));
    }

    public function testItAddsATransactionCallbackForInterfaceBasedAfterCommitJobs()
    {
        $background = new BackgroundQueue();
        $background->setConnectionName('background');
        $container = $this->getContainer();
        $transactionManager = m::mock(DatabaseTransactionsManager::class);
        $transactionManager->shouldReceive('addCallback')->once()->andReturn(null);
        $container->instance('db.transactions', $transactionManager);

        $background->setContainer($container);
        run(fn () => $background->push(new BackgroundQueueAfterCommitInterfaceJob()));
    }

    protected function getContainer(): Container
    {
        return new Container();
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
        throw new Exception();
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
