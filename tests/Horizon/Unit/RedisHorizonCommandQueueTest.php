<?php

declare(strict_types=1);

namespace Hypervel\Tests\Horizon\Unit;

use Hypervel\Contracts\Redis\Factory;
use Hypervel\Horizon\RedisHorizonCommandQueue;
use Hypervel\Redis\RedisProxy;
use Hypervel\Tests\Horizon\UnitTestCase;
use Mockery as m;

class RedisHorizonCommandQueueTest extends UnitTestCase
{
    public function testPendingUsesPipelineOnStandaloneRedis(): void
    {
        $queue = $this->createQueue(cluster: false, method: 'pipeline');

        $this->assertSame('pause', $queue->pending('master')[0]->command);
    }

    public function testPendingUsesTransactionOnRedisCluster(): void
    {
        $queue = $this->createQueue(cluster: true, method: 'transaction');

        $this->assertSame('pause', $queue->pending('master')[0]->command);
    }

    private function createQueue(bool $cluster, string $method): RedisHorizonCommandQueue
    {
        $pipeline = m::mock();
        $pipeline->shouldReceive('lRange')->once()->with('commands:master', 0, 0);
        $pipeline->shouldReceive('lTrim')->once()->with('commands:master', 1, -1);

        $connection = m::mock(RedisProxy::class);
        $connection->shouldReceive('lLen')->once()->with('commands:master')->andReturn(1);
        $connection->shouldReceive('isCluster')->once()->andReturn($cluster);
        $connection->shouldReceive($method)
            ->once()
            ->andReturnUsing(function (callable $callback) use ($pipeline): array {
                $callback($pipeline);

                return [[json_encode(['command' => 'pause', 'options' => []])], true];
            });
        $connection->shouldNotReceive($method === 'pipeline' ? 'transaction' : 'pipeline');

        $redis = m::mock(Factory::class);
        $redis->shouldReceive('connection')->twice()->with('horizon')->andReturn($connection);

        return new RedisHorizonCommandQueue($redis);
    }
}
