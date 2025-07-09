<?php

declare(strict_types=1);

namespace Hypervel\Permission;

use Hypervel\Support\ServiceProvider;

class PermissionServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->registerPublishing();
    }

    public function registerPublishing()
    {
        $this->publishes([
            __DIR__ . '/../config/permission.php' => config_path('permission.php'),
        ], 'permission-config');

        $this->publishes([
            __DIR__ . '/../database/migrations/2025_07_02_000000_create_permission_tables.php' => database_path(
                'migrations/2025_07_02_000000_create_permission_tables.php'
            ),
        ], 'permission-migrations');
    }
}
