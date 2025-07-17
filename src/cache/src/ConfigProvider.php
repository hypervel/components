<?php

declare(strict_types=1);

namespace Hypervel\Cache;

use Hypervel\Cache\Console\ClearCommand;
use Hypervel\Cache\Console\PruneDbExpiredCommand;
use Hypervel\Cache\Contracts\Factory;
use Hypervel\Cache\Contracts\Store;
use Hypervel\Cache\Listeners\CreateSwooleTable;
use Hypervel\Cache\Listeners\CreateTimer;

class ConfigProvider
{
    public function __invoke(): array
    {
        return [
            'dependencies' => [
                Factory::class => CacheManager::class,
                Store::class => fn ($container) => $container->get(CacheManager::class)->driver(),
            ],
            'listeners' => [
                CreateSwooleTable::class,
                CreateTimer::class,
            ],
            'commands' => [
                ClearCommand::class,
                PruneDbExpiredCommand::class,
            ],
            'publish' => [
                [
                    'id' => 'config',
                    'description' => 'The config for cache.',
                    'source' => __DIR__ . '/../publish/cache.php',
                    'destination' => BASE_PATH . '/config/autoload/cache.php',
                ],
            ],
        ];
    }
}
