<?php

declare(strict_types=1);

namespace Hypervel\Tests\Redis;

use Hypervel\Contracts\Container\Container as ContainerContract;
use Hypervel\Contracts\Pool\ConnectionInterface;
use Hypervel\Pool\Connection;
use Hypervel\Redis\Pool\PoolFactory;
use Hypervel\Redis\Pool\RedisPool;
use Hypervel\Redis\RedisConfig;
use Hypervel\Tests\TestCase;
use Mockery as m;

/**
 * @internal
 * @coversNothing
 */
class PoolFactoryTest extends TestCase
{
    public function testGetPoolReturnsSameInstance()
    {
        $container = $this->mockContainerWithPools();

        $factory = new PoolFactory($container);

        $pool1 = $factory->getPool('default');
        $pool2 = $factory->getPool('default');

        $this->assertSame($pool1, $pool2);
    }

    public function testGetPoolReturnsDifferentInstancesForDifferentNames()
    {
        $container = $this->mockContainerWithPools();

        $factory = new PoolFactory($container);

        $pool1 = $factory->getPool('default');
        $pool2 = $factory->getPool('cache');

        $this->assertNotSame($pool1, $pool2);
    }

    public function testFlushAll()
    {
        $container = $this->mockContainerWithPools();

        $factory = new PoolFactory($container);

        $pool1 = $factory->getPool('default');
        $pool2 = $factory->getPool('cache');

        $connection1 = $pool1->get();
        $connection2 = $pool1->get();
        $connection3 = $pool2->get();

        $pool1->release($connection1);
        $pool1->release($connection2);
        $pool2->release($connection3);

        $this->assertSame(2, $pool1->getConnectionsInChannel());
        $this->assertSame(1, $pool2->getConnectionsInChannel());

        $factory->flushAll();

        $this->assertSame(0, $pool1->getConnectionsInChannel());
        $this->assertSame(0, $pool2->getConnectionsInChannel());
    }

    private function mockContainerWithPools(): m\MockInterface|ContainerContract
    {
        $connectionConfig = [
            'host' => 'localhost',
            'port' => 6379,
            'db' => 0,
            'pool' => [
                'min_connections' => 1,
                'max_connections' => 10,
                'connect_timeout' => 10.0,
                'wait_timeout' => 3.0,
                'heartbeat' => -1,
                'max_idle_time' => 60.0,
            ],
        ];

        $redisConfig = m::mock(RedisConfig::class);
        $redisConfig->shouldReceive('connectionConfig')->andReturn($connectionConfig);

        $container = m::mock(ContainerContract::class);
        $container->shouldReceive('make')->with(RedisConfig::class)->andReturn($redisConfig);
        $container->shouldReceive('has')->andReturn(false);
        $container->shouldReceive('make')->with(RedisPool::class, m::any())->andReturnUsing(
            fn ($class, $args) => new PoolFactoryTestPool($container, $args['name'])
        );

        return $container;
    }
}

/**
 * @internal
 */
class PoolFactoryTestPool extends RedisPool
{
    protected function createConnection(): ConnectionInterface
    {
        return new PoolFactoryTestConnection($this->container, $this);
    }
}

/**
 * @internal
 */
class PoolFactoryTestConnection extends Connection
{
    public function close(): bool
    {
        return true;
    }

    public function reconnect(): bool
    {
        return true;
    }

    public function getActiveConnection(): static
    {
        return $this;
    }
}
