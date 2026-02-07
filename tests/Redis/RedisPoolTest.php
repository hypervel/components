<?php

declare(strict_types=1);

namespace Hypervel\Tests\Redis;

use Hyperf\Contract\ConnectionInterface;
use Hyperf\Contract\FrequencyInterface;
use Hypervel\Pool\Connection;
use Hypervel\Pool\LowFrequencyInterface;
use Hypervel\Redis\Pool\RedisPool;
use Hypervel\Redis\RedisConfig;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Psr\Container\ContainerInterface;

/**
 * @internal
 * @coversNothing
 */
class RedisPoolTest extends TestCase
{
    public function testPoolConfigComesFromRedisConfig(): void
    {
        $connectionConfig = [
            'host' => 'redis',
            'port' => 16379,
            'db' => 0,
            'pool' => [
                'min_connections' => 1,
                'max_connections' => 30,
                'connect_timeout' => 10.0,
                'wait_timeout' => 3.0,
                'heartbeat' => -1,
                'max_idle_time' => 1,
            ],
        ];

        $container = $this->mockContainerWithRedisConfig($connectionConfig);
        $pool = new RedisPool($container, 'default');

        $this->assertSame($connectionConfig, $pool->getConfig());
    }

    public function testLowFrequencyFlushClosesIdleConnections(): void
    {
        TestPoolConnection::reset();

        $connectionConfig = [
            'host' => 'redis',
            'port' => 16379,
            'db' => 0,
            'pool' => [
                'min_connections' => 1,
                'max_connections' => 30,
                'connect_timeout' => 10.0,
                'wait_timeout' => 3.0,
                'heartbeat' => -1,
                'max_idle_time' => 1,
            ],
        ];

        $container = $this->mockContainerWithRedisConfig($connectionConfig);
        $container->shouldReceive('has')->andReturn(false);

        $pool = new TestRedisPool($container, 'default');

        $connection1 = $pool->get();
        $connection2 = $pool->get();
        $connection3 = $pool->get();

        $this->assertSame(3, $pool->getCurrentConnections());

        $connection1->release();
        $connection2->release();
        $connection3->release();

        $this->assertSame(3, $pool->getCurrentConnections());

        $pool->setFrequencyForTest(new AlwaysLowFrequency());
        $connection = $pool->get();

        $this->assertSame(1, $pool->getCurrentConnections());
        $this->assertSame(2, TestPoolConnection::$closeCount);

        $connection->release();

        $this->assertSame(1, $pool->getCurrentConnections());
        $this->assertSame(1, $pool->getConnectionsInChannel());
    }

    /**
     * @param array<string, mixed> $connectionConfig
     */
    private function mockContainerWithRedisConfig(array $connectionConfig): m\MockInterface|ContainerInterface
    {
        $redisConfig = m::mock(RedisConfig::class);
        $redisConfig->shouldReceive('connectionConfig')->once()->with('default')->andReturn($connectionConfig);

        $container = m::mock(ContainerInterface::class);
        $container->shouldReceive('get')->with(RedisConfig::class)->once()->andReturn($redisConfig);

        return $container;
    }
}

class TestRedisPool extends RedisPool
{
    public function setFrequencyForTest(FrequencyInterface|LowFrequencyInterface $frequency): void
    {
        $this->frequency = $frequency;
    }

    protected function createConnection(): ConnectionInterface
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

class AlwaysLowFrequency implements FrequencyInterface, LowFrequencyInterface
{
    public function __construct(?\Hypervel\Pool\Pool $pool = null)
    {
    }

    public function hit(int $number = 1): bool
    {
        return true;
    }

    public function frequency(): float
    {
        return 0.0;
    }

    public function isLowFrequency(): bool
    {
        return true;
    }
}
