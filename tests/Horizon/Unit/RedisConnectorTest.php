<?php

declare(strict_types=1);

namespace Hypervel\Tests\Horizon\Unit;

use Hypervel\Contracts\Redis\Factory as Redis;
use Hypervel\Horizon\Connectors\RedisConnector;
use Hypervel\Horizon\RedisQueue;
use Hypervel\Tests\Horizon\UnitTestCase;
use Mockery as m;

class RedisConnectorTest extends UnitTestCase
{
    public function testConnectSucceedsWithoutAfterCommitConfig()
    {
        $redis = m::mock(Redis::class);
        $connector = new RedisConnector($redis);

        $queue = $connector->connect([
            'queue' => 'default',
        ]);

        $this->assertInstanceOf(RedisQueue::class, $queue);
    }
}
