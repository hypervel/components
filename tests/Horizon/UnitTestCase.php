<?php

declare(strict_types=1);

namespace Hypervel\Tests\Horizon;

use Hypervel\Container\Container;
use Hypervel\Contracts\Redis\Factory;
use Hypervel\Redis\Pool\PoolManager;
use Hypervel\Redis\Pool\RedisPool;
use Hypervel\Redis\RedisConnection;
use Hypervel\Redis\RedisProxy;
use Hypervel\Redis\RedisSentinelFactory;
use Hypervel\Tests\Redis\Fixtures\PhpRedisClusterConnectionStub;
use Hypervel\Tests\Redis\Fixtures\PhpRedisConnectionStub;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\DoubleInterface;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use Redis;
use RedisCluster;

abstract class UnitTestCase extends TestCase
{
    use VerifiesDoubles;

    /**
     * Create a real Redis forwarding path with an isolated native client.
     *
     * @return array{DoubleInterface&RedisProxy, (DoubleInterface&Redis)|(DoubleInterface&RedisCluster), DoubleInterface&RedisConnection}
     */
    protected function redisConnection(bool $cluster = false): array
    {
        $client = Double::for($cluster ? RedisCluster::class : Redis::class)->strict();
        $client->allows('getMode')->returns(Redis::ATOMIC);

        $connection = Double::for(
            ($cluster ? new PhpRedisClusterConnectionStub : new PhpRedisConnectionStub)->setActiveConnection($client),
        )->passthru();
        $connection->allows('release');

        $pool = Double::for(RedisPool::class)->strict();
        $pool->allows('borrow')->returns($connection);
        $pool->allows('getConfig')->returns(['cluster' => ['enabled' => $cluster]]);

        $manager = Double::for(new PoolManager(new Container))->passthru();
        $manager->allows('pool')->with('horizon')->returns($pool);

        $proxy = Double::for(new RedisProxy($manager, 'horizon', new RedisSentinelFactory))->passthru();

        return [$proxy, $client, $connection];
    }

    /**
     * Create a Redis factory for the given Horizon connection.
     */
    protected function redisFactory(RedisProxy $connection): Factory
    {
        $redis = Double::for(Factory::class)->strict();
        $redis->expects('connection')->with('horizon')->returns($connection);

        return $redis;
    }
}
