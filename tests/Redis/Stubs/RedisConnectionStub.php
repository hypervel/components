<?php

declare(strict_types=1);

namespace Hypervel\Tests\Redis\Stubs;

use Hyperf\Contract\PoolInterface;
use Hypervel\Redis\RedisConnection;
use Mockery as m;
use Psr\Container\ContainerInterface;
use Redis;
use RedisCluster;
use Throwable;

class RedisConnectionStub extends RedisConnection
{
    protected Redis|RedisCluster|null $redisConnection = null;

    /**
     * Flexible constructor for testing.
     *
     * Can be called with no arguments (for simple tests that inject via setActiveConnection()),
     * or with container/pool/config for tests that need full RedisConnection behavior.
     */
    public function __construct(?ContainerInterface $container = null, ?PoolInterface $pool = null, array $config = [])
    {
        if ($container !== null && $pool !== null) {
            // Full initialization for tests that need it (e.g., RedisConnectionTest)
            parent::__construct($container, $pool, $config);
        }
        // Otherwise, skip parent constructor for simple tests
    }

    public function reconnect(): bool
    {
        return true;
    }

    public function check(): bool
    {
        return true;
    }

    public function getActiveConnection(): static
    {
        if ($this->connection !== null) {
            return $this;
        }

        // Use shouldIgnoreMissing() to prevent falling through to real Redis
        // methods when expectations don't match (which causes "Redis server went away")
        $connection = $this->redisConnection
            ?? m::mock(Redis::class)->shouldIgnoreMissing();

        $this->connection = $connection;

        return $this;
    }

    public function setActiveConnection(Redis|RedisCluster $connection): static
    {
        $this->redisConnection = $connection;
        $this->connection = $connection;

        return $this;
    }

    /**
     * Get the underlying Redis connection for test mocking.
     */
    public function getConnection(): Redis|RedisCluster
    {
        $this->getActiveConnection();

        return $this->connection;
    }

    protected function retry($name, $arguments, Throwable $exception)
    {
        throw $exception;
    }
}
