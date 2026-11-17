<?php

declare(strict_types=1);

namespace Hypervel\Tests\Redis\Fixtures;

use Hypervel\Contracts\ConnectionPool\ConnectionPool;
use Hypervel\Contracts\Container\Container;
use Hypervel\Redis\PhpRedisClusterConnection;
use RedisCluster;

class PhpRedisClusterConnectionStub extends PhpRedisClusterConnection
{
    protected ?RedisCluster $redisConnection = null;

    /**
     * Flexible constructor for testing.
     *
     * Can be called with no arguments (for simple tests that inject via setActiveConnection()),
     * or with container/pool/config for tests that need full behavior.
     */
    public function __construct(?Container $container = null, ?ConnectionPool $pool = null, array $config = [])
    {
        if ($container !== null && $pool !== null) {
            // Call the grandparent to store config without reconnecting.
            \Hypervel\Redis\RedisConnection::__construct($container, $pool, $config);
        }
        // Skip reconnect() — tests inject connections via setActiveConnection()
    }

    /**
     * Keep reconnection under test control.
     */
    public function reconnect(): bool
    {
        return true;
    }

    /**
     * Treat the injected client as healthy.
     */
    public function check(): bool
    {
        return true;
    }

    /**
     * Restore the injected client without opening a socket.
     */
    public function getActiveConnection(): static
    {
        if ($this->connection !== null) {
            return $this;
        }

        $this->connection = $this->redisConnection;

        return $this;
    }

    /**
     * Set the native client used by the test.
     */
    public function setActiveConnection(RedisCluster $connection): static
    {
        $this->redisConnection = $connection;
        $this->connection = $connection;

        return $this;
    }

    /**
     * Get the connection configuration.
     *
     * @return array<string, mixed>
     */
    public function getConfigForTest(): array
    {
        return $this->config;
    }
}
