<?php

declare(strict_types=1);

namespace Hypervel\Tests\Redis\Stubs;

use Hypervel\Redis\RedisConnection;
use Psr\EventDispatcher\EventDispatcherInterface;
use Redis;
use Throwable;

class RedisClientConnectionStub extends RedisConnection
{
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
        return $this;
    }

    public function setActiveConnection(Redis $connection): static
    {
        $this->connection = $connection;

        return $this;
    }

    public function setEventDispatcher(EventDispatcherInterface $eventDispatcher): static
    {
        $this->eventDispatcher = $eventDispatcher;

        return $this;
    }

    protected function retry($name, $arguments, Throwable $exception)
    {
        throw $exception;
    }
}
