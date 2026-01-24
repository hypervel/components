<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue;

use Exception;
use Hyperf\Di\Container;
use Hyperf\Di\Definition\DefinitionSource;
use Hypervel\Bus\Contracts\Dispatcher;
use Hypervel\Database\DatabaseTransactionsManager;
use Hypervel\Contracts\Queue\QueueableEntity;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Contracts\Queue\ShouldQueueAfterCommit;
use Hypervel\Queue\InteractsWithQueue;
use Hypervel\Queue\Jobs\SyncJob;
use Hypervel\Queue\SyncQueue;
use LogicException;
use Mockery as m;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * @internal
 * @coversNothing
 */
class QueueSyncQueueTest extends TestCase
{
    protected function tearDown(): void
    {
        m::close();
    }

    public function testPushShouldFireJobInstantly()
    {
        unset($_SERVER['__sync.test']);

        $sync = new SyncQueue();
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

        $sync = new SyncQueue();
        $sync->setConnectionName('sync');
        $container = $this->getContainer();
        $events = m::mock(EventDispatcherInterface::class);
        $events->shouldReceive('dispatch')->times(3);
        $container->set(EventDispatcherInterface::class, $events);
        $sync->setContainer($container);

        try {
            $sync->push(FailingSyncQueueTestHandler::class, ['foo' => 'bar']);
        } catch (Exception) {
            $this->assertTrue($_SERVER['__sync.failed']);
        }
    }

    public function testCreatesPayloadObject()
    {
        $sync = new SyncQueue();
        $sync->setConnectionName('sync');
        $container = $this->getContainer();
        $events = m::mock(EventDispatcherInterface::class);
        $events->shouldReceive('dispatch');
        $container->set(EventDispatcherInterface::class, $events);
        $dispatcher = m::mock(Dispatcher::class);
        $dispatcher->shouldReceive('getCommandHandler')->once()->andReturn(false);
        $dispatcher->shouldReceive('dispatchNow')->once();
        $container->set(Dispatcher::class, $dispatcher);
        $sync->setContainer($container);

        SyncQueue::createPayloadUsing(function ($connection, $queue, $payload) {
            return ['data' => ['extra' => 'extraValue']];
        });

        try {
            $sync->push(new SyncQueueJob());
        } catch (LogicException $e) {
            $this->assertSame('extraValue', $e->getMessage());
        }

        SyncQueue::createPayloadUsing(null);
    }

    public function testItAddsATransactionCallbackForAfterCommitJobs()
    {
        $sync = new SyncQueue();
        $sync->setConnectionName('sync');
        $container = $this->getContainer();
        $transactionManager = m::mock(DatabaseTransactionsManager::class);
        $transactionManager->shouldReceive('addCallback')->once()->andReturn(null);
        $container->set(DatabaseTransactionsManager::class, $transactionManager);

        $sync->setContainer($container);
        $sync->push(new SyncQueueAfterCommitJob());
    }

    public function testItAddsATransactionCallbackForInterfaceBasedAfterCommitJobs()
    {
        $sync = new SyncQueue();
        $sync->setConnectionName('sync');
        $container = $this->getContainer();
        $transactionManager = m::mock(DatabaseTransactionsManager::class);
        $transactionManager->shouldReceive('addCallback')->once()->andReturn(null);
        $container->set(DatabaseTransactionsManager::class, $transactionManager);

        $sync->setContainer($container);
        $sync->push(new SyncQueueAfterCommitInterfaceJob());
    }

    protected function getContainer(): Container
    {
        return new Container(
            new DefinitionSource([])
        );
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
        throw new Exception();
    }

    public function failed()
    {
        $_SERVER['__sync.failed'] = true;
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
