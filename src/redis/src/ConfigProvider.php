<?php

declare(strict_types=1);

namespace Hypervel\Redis;

use Hyperf\Redis\Pool\RedisPool as HyperfRedisPool;

class ConfigProvider
{
    public function __invoke(): array
    {
        return [
            'dependencies' => [
                HyperfRedisPool::class => RedisPool::class,
            ],
        ];
    }
}
