<?php

declare(strict_types=1);

namespace Hypervel\Cache;

use Hypervel\Cache\Console\ClearCommand;
use Hypervel\Cache\Console\PruneDbExpiredCommand;
use Hypervel\Cache\Listeners\CreateSwooleTable;
use Hypervel\Cache\Listeners\CreateTimer;
use Hypervel\Cache\Redis\Console\BenchmarkCommand;
use Hypervel\Cache\Redis\Console\DoctorCommand;
use Hypervel\Cache\Redis\Console\PruneStaleTagsCommand;
use Hypervel\Contracts\Cache\Factory;
use Hypervel\Contracts\Cache\Store;

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
                BenchmarkCommand::class,
                ClearCommand::class,
                DoctorCommand::class,
                PruneDbExpiredCommand::class,
                PruneStaleTagsCommand::class,
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
