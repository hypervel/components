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

        $connection = $this->redisConnection
            ?? Mockery::mock(Redis::class);

        return $this->connection = $connection;
    }

    public function setActiveConnection(Redis $connection): static
    {
        $this->redisConnection = $connection;

        return $this;
    }

    protected function retry($name, $arguments, Throwable $exception)
    {
        throw $exception;
    }
}
