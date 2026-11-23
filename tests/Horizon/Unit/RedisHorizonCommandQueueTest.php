<?php

declare(strict_types=1);

namespace Hypervel\Tests\Horizon\Unit;

use Hypervel\Contracts\Redis\Factory;
use Hypervel\Horizon\RedisHorizonCommandQueue;
use Hypervel\Tests\Horizon\UnitTestCase;
use JMac\Testing\Double;

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
        [$connection, $client] = $this->redisConnection($cluster);
        $client->expects('lLen')->with('commands:master')->returns(1);
        $connection->expects($method === 'pipeline' ? 'transaction' : 'pipeline')->never();
        $client->expects($cluster ? 'multi' : 'pipeline')->returns($client);
        $client->expects('lRange')->with('commands:master', 0, 0)->returns($client);
        $client->expects('lTrim')->with('commands:master', 1, -1)->returns($client);
        $client->expects('exec')->returns([[json_encode(['command' => 'pause', 'options' => []])], true]);

        $redis = Double::for(Factory::class)->strict();
        $redis->expects('connection')->times(2)->with('horizon')->returns($connection);

        return new RedisHorizonCommandQueue($redis);
    }
}
