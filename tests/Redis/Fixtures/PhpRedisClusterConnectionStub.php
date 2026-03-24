<?php

declare(strict_types=1);

namespace Hypervel\Tests\Redis\Fixtures;

use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Pool\PoolInterface;
use Hypervel\Redis\PhpRedisClusterConnection;
use Mockery as m;
use Redis;
use RedisCluster;
use RedisException;

class PhpRedisClusterConnectionStub extends PhpRedisClusterConnection
{
    protected ?RedisCluster $redisConnection = null;

    /**
     * Flexible constructor for testing.
     *
     * Can be called with no arguments (for simple tests that inject via setActiveConnection()),
     * or with container/pool/config for tests that need full behavior.
     */
    public function __construct(?Container $container = null, ?PoolInterface $pool = null, array $config = [])
    {
        if ($container !== null && $pool !== null) {
            // Call grandparent to merge config without reconnecting
            \Hypervel\Redis\RedisConnection::__construct($container, $pool, $config);
        }
        // Skip reconnect() — tests inject connections via setActiveConnection()
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

        // Use shouldIgnoreMissing() to prevent falling through to real RedisCluster
        // methods when expectations don't match
        $connection = $this->redisConnection
            ?? m::mock(RedisCluster::class)->shouldIgnoreMissing();

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

    /**
     * Get the merged connection configuration.
     *
     * @return array<string, mixed>
     */
    public function getConfigForTest(): array
    {
        return $this->config;
    }

    protected function retry(string $name, array $arguments, RedisException $exception): mixed
    {
        throw $exception;
    }
}
