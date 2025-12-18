<?php

declare(strict_types=1);

namespace Hypervel\Redis;

use Hyperf\Redis\Pool\PoolFactory;

class RedisProxy extends Redis
{
    public function __construct(PoolFactory $factory, string $pool)
    {
        parent::__construct($factory);

        $this->poolName = $pool;
    }
}
