<?php

declare(strict_types=1);

namespace Hypervel\Redis;

use Hypervel\Redis\Pool\PoolFactory;

class RedisProxy extends Redis
{
    /**
     * Create a new Redis proxy instance.
     */
    public function __construct(PoolFactory $factory, string $pool)
    {
        parent::__construct($factory);

        $this->poolName = $pool;
    }
}
