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
use JMac\Testing\Double;
use JMac\Testing\DoubleInterface;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;

class RedisPoolTest extends TestCase
{
    use VerifiesDoubles;

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
        $pool = new RedisPool($container->instance(), 'default');
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
        $pool = new RedisPool($container->instance(), 'default');

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
        $container = Double::for(Container::class, override: true)->strict();
        $container->expects('make')->with(RedisConfig::class)->returns($redisConfig);
        $container->allows('has')->with(StdoutLoggerInterface::class)->returns(false);
        $pool = new RedisPool($container->instance(), 'default');

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
        $container->allows('has')->returns(false);
        $container->allows('bound')->with('events')->returns(false);

        $pool = new TestRedisPool($container->instance(), 'default');

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
    private function mockContainerWithRedisConfig(array $connectionConfig): DoubleInterface
    {
        $redisConfig = Double::for(RedisConfig::class)->strict();
        $redisConfig->expects('connectionConfig')->with('default')->returns($connectionConfig);

        $container = Double::for(Container::class, override: true)->strict();
        $container->expects('make')->with(RedisConfig::class)->returns($redisConfig);
        $container->allows('has')->with(StdoutLoggerInterface::class)->returns(false);

        return $container;
    }
}

class TestRedisPool extends RedisPool
{
    /**
     * Set the usage tracker for the test.
     */
    public function setUsageTrackerForTest(UsageTracker $tracker): void
    {
        $this->usageTracker = $tracker;
        $this->usageTrackerInitialized = true;
    }

    /**
     * Create a tracked test connection.
     */
    protected function createConnection(): PoolConnection
    {
        return new TestPoolConnection($this->container, $this);
    }
}

class TestPoolConnection extends Connection
{
    public static int $closeCount = 0;

    /**
     * Reset the close count.
     */
    public static function reset(): void
    {
        self::$closeCount = 0;
    }

    /**
     * Record closing the connection.
     */
    public function close(): bool
    {
        ++self::$closeCount;

        return true;
    }

    /**
     * Report a successful test reconnection.
     */
    public function reconnect(): bool
    {
        return true;
    }

    /**
     * Return the test connection.
     */
    public function getActiveConnection(): static
    {
        return $this;
    }
}

class AlwaysTrimIdle implements UsageTracker
{
    /**
     * Leave the test policy unchanged on borrow.
     */
    public function recordBorrow(): void
    {
    }

    /**
     * Always request trimming excess idle connections.
     */
    public function shouldTrimExcessIdle(): bool
    {
        return true;
    }
}
