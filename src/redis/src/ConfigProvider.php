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
            'publish' => [
                [
                    'id' => 'config',
                    'description' => 'The config of redis client.',
                    'source' => __DIR__ . '/../publish/redis.php',
                    'destination' => BASE_PATH . '/config/autoload/redis.php',
                ],
            ],
        ];
    }
}
