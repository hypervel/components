<?php

declare(strict_types=1);

namespace Hypervel\Tests\Horizon\Unit;

use Hypervel\Contracts\Redis\Factory;
use Hypervel\Horizon\RedisQueue;
use Hypervel\Redis\RedisProxy;
use Hypervel\Tests\Horizon\UnitTestCase;
use Hypervel\Tests\Queue\Fixtures\IntegerQueueName;
use Mockery as m;

class RedisQueueTest extends UnitTestCase
{
    public function testReadyNowReadsTheClusterSafeQueueKey(): void
    {
        $connection = m::mock(RedisProxy::class);
        $connection->shouldReceive('isCluster')->once()->andReturnTrue();
        $connection->shouldReceive('lLen')->once()->with('queues:{critical}')->andReturn(3);
        $connection->shouldReceive('lLen')->once()->with('queues:{0}')->andReturn(4);

        $redis = m::mock(Factory::class);
        $redis->shouldReceive('connection')->times(3)->with('default')->andReturn($connection);

        $queue = new RedisQueue($redis, 'default', 'default');

        $this->assertSame(3, $queue->readyNow('critical'));
        $this->assertSame(4, $queue->readyNow(IntegerQueueName::Zero));
    }
}
