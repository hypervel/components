<?php

declare(strict_types=1);

namespace Hypervel\Tests\Redis;

use Hypervel\Config\Repository;
use Hypervel\ConnectionPool\Connection;
use Hypervel\Contracts\ConnectionPool\Connection as PoolConnection;
use Hypervel\Contracts\ConnectionPool\UsageTracker;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Log\StdoutLoggerInterface;
use Hypervel\Redis\Pool\RedisPool;
use Hypervel\Redis\RedisConfig;
use Hypervel\Tests\TestCase;
use Mockery as m;

class RedisPoolTest extends TestCase
{
    public function testPoolConnectTimeoutConfiguresTheNativeRedisTimeout(): void
    {
        $connectionConfig = [
            'host' => 'redis',
            'port' => 16379,
            'database' => 0,
            'timeout' => null,
            'pool' => [
                'min_retained_connections' => 1,
                'max_connections' => 30,
                'connect_timeout' => 1.25,
                'wait_timeout' => 3.0,
                'heartbeat_interval' => null,
                'max_idle_time' => 1,
            ],
        ];

        $container = $this->mockContainerWithRedisConfig($connectionConfig);
        $pool = new RedisPool($container, 'default');
        $expectedConfig = $connectionConfig;
        $expectedConfig['timeout'] = 1.25;

        $this->assertSame($expectedConfig, $pool->getConfig());
    }

    public function testPoolConnectTimeoutDoesNotOverrideTheNativeRedisTimeout(): void
    {
        $connectionConfig = [
            'host' => 'redis',
            'port' => 16379,
            'database' => 0,
            'timeout' => 7.0,
            'pool' => [
                'min_retained_connections' => 1,
                'max_connections' => 30,
                'connect_timeout' => 1.25,
                'wait_timeout' => 3.0,
                'heartbeat_interval' => null,
                'max_idle_time' => 1,
            ],
        ];

        $container = $this->mockContainerWithRedisConfig($connectionConfig);
        $pool = new RedisPool($container, 'default');

        $this->assertSame($connectionConfig, $pool->getConfig());
    }

    public function testEventOverrideDoesNotRetrofitExistingPoolConfiguration(): void
    {
        $redisConfig = new RedisConfig(new Repository([
            'database' => [
                'redis' => [
                    'options' => [],
                    'default' => [
                        'host' => 'redis',
                        'port' => 16379,
                        'database' => 0,
                        'timeout' => null,
                        'events' => false,
                        'options' => [],
                        'pool' => [
                            'min_retained_connections' => 1,
                            'max_connections' => 30,
                            'connect_timeout' => 1.25,
                            'wait_timeout' => 3.0,
                            'heartbeat_interval' => null,
                            'max_idle_time' => 1,
                        ],
                    ],
                ],
            ],
        ]));
        $container = m::mock(Container::class);
        $container->shouldReceive('make')->with(RedisConfig::class)->once()->andReturn($redisConfig);
        $container->shouldReceive('has')->with(StdoutLoggerInterface::class)->andReturn(false);
        $pool = new RedisPool($container, 'default');

        $redisConfig->enableEvents();

        $this->assertFalse($pool->getConfig()['events']);
        $this->assertTrue($redisConfig->connectionConfig('default')['events']);
    }

    public function testUsagePolicyTrimsExcessIdleConnections(): void
    {
        TestPoolConnection::reset();

        $connectionConfig = [
            'host' => 'redis',
            'port' => 16379,
            'database' => 0,
            'timeout' => null,
            'pool' => [
                'min_retained_connections' => 1,
                'max_connections' => 30,
                'connect_timeout' => 10.0,
                'wait_timeout' => 3.0,
                'heartbeat_interval' => null,
                'max_idle_time' => 1,
            ],
        ];

        $container = $this->mockContainerWithRedisConfig($connectionConfig);
        $container->shouldReceive('has')->andReturn(false);
        $container->shouldReceive('bound')->with('events')->andReturn(false);

        $pool = new TestRedisPool($container, 'default');

        $connection1 = $pool->borrow();
        $connection2 = $pool->borrow();
        $connection3 = $pool->borrow();

        $this->assertSame(3, $pool->getManagedCount());

        $connection1->release();
        $connection2->release();
        $connection3->release();

        $this->assertSame(3, $pool->getManagedCount());

        $pool->setUsageTrackerForTest(new AlwaysTrimIdle);
        $connection = $pool->borrow();

        $this->assertSame(1, $pool->getManagedCount());
        $this->assertSame(2, TestPoolConnection::$closeCount);

        $connection->release();

        $this->assertSame(1, $pool->getManagedCount());
        $this->assertSame(1, $pool->getIdleCount());
    }

    /**
     * Mock a container with the given Redis configuration.
     *
     * @param array<string, mixed> $connectionConfig
     */
    private function mockContainerWithRedisConfig(array $connectionConfig): m\MockInterface|Container
    {
        $redisConfig = m::mock(RedisConfig::class);
        $redisConfig->shouldReceive('connectionConfig')->once()->with('default')->andReturn($connectionConfig);

        $container = m::mock(Container::class);
        $container->shouldReceive('make')->with(RedisConfig::class)->once()->andReturn($redisConfig);
        $container->shouldReceive('has')->with(StdoutLoggerInterface::class)->andReturn(false);

        return $container;
    }
}

class TestRedisPool extends RedisPool
{
    public function setUsageTrackerForTest(UsageTracker $tracker): void
    {
        $this->usageTracker = $tracker;
        $this->usageTrackerInitialized = true;
    }

    protected function createConnection(): PoolConnection
    {
        return new TestPoolConnection($this->container, $this);
    }
}

class TestPoolConnection extends Connection
{
    public static int $closeCount = 0;

    public static function reset(): void
    {
        self::$closeCount = 0;
    }

    public function close(): bool
    {
        ++self::$closeCount;

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

class AlwaysTrimIdle implements UsageTracker
{
    public function recordBorrow(): void
    {
    }

    public function shouldTrimExcessIdle(): bool
    {
        return true;
    }
}
