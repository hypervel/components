<?php

declare(strict_types=1);

namespace Hypervel\Tests\Redis\Stubs;

use Hypervel\Redis\RedisConnection;
use Mockery;
use Redis;
use Throwable;

class RedisConnectionStub extends RedisConnection
{
    protected ?Redis $redisConnection = null;

    public function reconnect(): bool
    {
        return true;
    }

    public function check(): bool
    {
        return true;
    }

    public function getActiveConnection(): Redis
    {
        if ($this->connection !== null) {
            return $this->connection;
        }

        // Use shouldIgnoreMissing() to prevent falling through to real Redis
        // methods when expectations don't match (which causes "Redis server went away")
        $connection = $this->redisConnection
            ?? Mockery::mock(Redis::class)->shouldIgnoreMissing();

        return $this->connection = $connection;
    }

    public function setActiveConnection(Redis $connection): static
    {
        $this->redisConnection = $connection;

        return $this;
    }

    /**
     * Get the underlying Redis connection for test mocking.
     */
    public function getConnection(): Redis
    {
        return $this->getActiveConnection();
    }

    protected function retry($name, $arguments, Throwable $exception)
    {
        throw $exception;
    }
}
