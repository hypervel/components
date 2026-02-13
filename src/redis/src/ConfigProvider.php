<?php

declare(strict_types=1);

namespace Hypervel\Redis;

class ConfigProvider
{
    /**
     * Get the Redis package configuration.
     */
    public function __invoke(): array
    {
        return [
            'dependencies' => [
                \Redis::class => Redis::class,
            ],
        ];
    }
}
