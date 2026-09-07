<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue;

use Hypervel\Bus\Dispatcher as BusDispatcher;
use Hypervel\Bus\Queueable;
use Hypervel\Bus\UniqueLock;
use Hypervel\Cache\Repository;
use Hypervel\Cache\WorkerArrayStore;
use Hypervel\Container\Container;
use Hypervel\Contracts\Bus\Dispatcher;
use Hypervel\Contracts\Cache\Repository as CacheContract;
use Hypervel\Contracts\Queue\ShouldBeUnique;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Foundation\Bus\PendingDispatch;
use Hypervel\Queue\NullQueue;
use Hypervel\Queue\QueueRoutes;
use Hypervel\Tests\TestCase;
use Mockery as m;

class QueueNullQueueTest extends TestCase
{
    public function testCreationTimeOfOldestPendingJobReturnsNull()
    {
        $queue = new NullQueue;

        $this->assertNull($queue->creationTimeOfOldestPendingJob());
        $this->assertNull($queue->creationTimeOfOldestPendingJob('custom'));
    }

    public function testInspectionReturnsEmptyCollections(): void
    {
        $queue = new NullQueue;

        $this->assertTrue($queue->pendingJobs()->isEmpty());
        $this->assertTrue($queue->delayedJobs()->isEmpty());
        $this->assertTrue($queue->reservedJobs()->isEmpty());
        $this->assertTrue($queue->allPendingJobs()->isEmpty());
        $this->assertTrue($queue->allDelayedJobs()->isEmpty());
        $this->assertTrue($queue->allReservedJobs()->isEmpty());
    }

    public function testPendingDispatchReleasesUniqueLockWhenNullQueueAcceptsNothing(): void
    {
        $container = new Container;
        $cache = new Repository(new WorkerArrayStore, ['store' => 'worker-array']);
        $queue = new NullQueue;
        $queue->setContainer($container);

        $routes = m::mock(QueueRoutes::class);
        $routes->shouldReceive('getConnection')->once()->andReturnNull();
        $routes->shouldReceive('getQueue')->once()->andReturnNull();

        $container->instance(CacheContract::class, $cache);
        $container->instance('queue.routes', $routes);
        $container->instance(Dispatcher::class, new BusDispatcher($container, static fn () => $queue));
        Container::setInstance($container);

        $job = new NullQueueUniqueJob;
        $pendingDispatch = new PendingDispatch($job);
        unset($pendingDispatch);

        $this->assertTrue((new UniqueLock($cache))->acquire(new NullQueueUniqueJob));
    }
}

class NullQueueUniqueJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;
}
