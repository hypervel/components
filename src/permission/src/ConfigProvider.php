<?php

declare(strict_types=1);

namespace Hypervel\Permission;

use Hypervel\Permission\Console\ShowCommand;
use Hypervel\Permission\Contracts\Factory;

class ConfigProvider
{
    public function __invoke(): array
    {
        return [
            'dependencies' => [
                Factory::class => PermissionManager::class,
            ],
            'commands' => [
                ShowCommand::class,
            ],
            'publish' => [
                [
                    'id' => 'config',
                    'description' => 'The config for permission.',
                    'source' => __DIR__ . '/../publish/permission.php',
                    'destination' => BASE_PATH . '/config/autoload/permission.php',
                ],
                [
                    'id' => 'migrations',
                    'description' => 'The migrations for permission.',
                    'source' => __DIR__ . '/../database/migrations/2025_07_02_000000_create_permission_tables.php',
                    'destination' => BASE_PATH . '/migrations/autoload/migrations/2025_07_02_000000_create_permission_tables.php',
                ],
            ],
        ];
    }
}
