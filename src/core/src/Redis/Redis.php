<?php

declare(strict_types=1);

namespace Hypervel\Redis;

use Hyperf\Redis\Redis as HyperfRedis;
use Hyperf\Redis\RedisFactory;
use Hyperf\Redis\RedisProxy;
use Hypervel\Context\ApplicationContext;

class Redis extends HyperfRedis
{
    /**
     * Get a Redis connection by name.
     */
    public function connection(string $name = 'default'): RedisProxy
    {
        return ApplicationContext::getContainer()
            ->get(RedisFactory::class)
            ->get($name);
    }
}
