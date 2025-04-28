<?php

declare(strict_types=1);

namespace Hypervel\Redis;

use Hyperf\Contract\ConnectionInterface;
use Hyperf\Redis\Pool\RedisPool as HyperfRedisPool;

class RedisPool extends HyperfRedisPool
{
    protected function createConnection(): ConnectionInterface
    {
        return new RedisConnection($this->container, $this, $this->config);
    }
}
