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
    public function testConnectSucceedsWithCompleteConfiguration(): void
    {
        $redis = m::mock(Redis::class);
        $connector = new RedisConnector($redis);

        $queue = $connector->connect([
            'queue' => 'default',
            'connection' => 'queue',
            'retry_after' => 90,
            'block_for' => null,
            'after_commit' => false,
            'migration_batch_size' => -1,
        ]);

        $this->assertInstanceOf(RedisQueue::class, $queue);
    }
}
