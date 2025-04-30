<?php

declare(strict_types=1);

namespace Hypervel\Redis;

use Hyperf\Redis\RedisConnection as HyperfRedisConnection;
use Redis;
use Throwable;

class RedisConnection extends HyperfRedisConnection
{
    public function __call($name, $arguments)
    {
        try {
            $result = in_array($name, ['subscribe', 'psubscribe'])
                ? $this->callSubscribe($name, $arguments)
                : $this->connection->{$name}(...$arguments);
        } catch (Throwable $exception) {
            $result = $this->retry($name, $arguments, $exception);
        }

        return $result;
    }

    protected function callSubscribe(string $name, array $arguments): mixed
    {
        $timeout = $this->connection->getOption(Redis::OPT_READ_TIMEOUT);

        // Set the read timeout to -1 to avoid connection timeout.
        $this->connection->setOption(Redis::OPT_READ_TIMEOUT, -1);

        try {
            return $this->connection->{$name}(...$arguments);
        } finally {
            // Restore the read timeout to the original value before
            // returning to the connection pool.
            $this->connection->setOption(Redis::OPT_READ_TIMEOUT, $timeout);
        }
    }
}
