<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue;

use Exception;
use Hypervel\Queue\FailoverQueue;
use Hypervel\Queue\QueueManager;
use Hypervel\Queue\RedisQueue;
use Hypervel\Queue\SyncQueue;
use Mockery as m;
use PHPUnit\Framework\TestCase;
use Hypervel\Contracts\Event\Dispatcher;

/**
 * @internal
 * @coversNothing
 */
class FailoverQueueTest extends TestCase
{
    public function testPushFailsOverOnException()
    {
        $failover = new FailoverQueue($queue = m::mock(QueueManager::class), $events = m::mock(Dispatcher::class), [
            'redis',
            'sync',
        ]);

        $queue->shouldReceive('connection')->once()->with('redis')->andReturn(
            $redis = m::mock(RedisQueue::class),
        );

        $queue->shouldReceive('connection')->once()->with('sync')->andReturn(
            $sync = m::mock(SyncQueue::class),
        );

        $events->shouldReceive('dispatch')->once();

        $redis->shouldReceive('push')->once()->andReturnUsing(
            fn () => throw new Exception('error')
        );

        $sync->shouldReceive('push')->once();

        $failover->push('some-job');
    }
}
