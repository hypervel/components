<?php

declare(strict_types=1);

namespace Hypervel\Tests\Horizon\Unit;

use Hypervel\Contracts\Redis\Factory;
use Hypervel\Horizon\RedisQueue;
use Hypervel\Redis\RedisProxy;
use Hypervel\Tests\Horizon\UnitTestCase;
use Mockery as m;

class RedisQueueTest extends UnitTestCase
{
    public function testReadyNowReadsTheClusterSafeQueueKey(): void
    {
        $connection = m::mock(RedisProxy::class);
        $connection->shouldReceive('isCluster')->once()->andReturnTrue();
        $connection->shouldReceive('lLen')->once()->with('queues:{critical}')->andReturn(4);

        $redis = m::mock(Factory::class);
        $redis->shouldReceive('connection')->twice()->with('default')->andReturn($connection);

        $queue = new RedisQueue($redis, 'default', 'default');

        $this->assertSame(4, $queue->readyNow('critical'));
    }
}
