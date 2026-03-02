<?php

declare(strict_types=1);

namespace Hypervel\Permission;

use Hypervel\Permission\Console\ShowCommand;
use Hypervel\Permission\Contracts\Factory;
use Hypervel\Support\ServiceProvider;

class PermissionServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the service provider.
     */
    public function boot(): void
    {
        $this->registerPublishing();
    }

    /**
     * Register the service provider.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../publish/permission.php',
            'permission'
        );

        $this->app->singleton(Factory::class, PermissionManager::class);

        $this->commands([
            ShowCommand::class,
        ]);
    }

    public function registerPublishing(): void
    {
        $this->publishes([
            __DIR__ . '/../publish/permission.php' => config_path('permission.php'),
        ], 'permission-config');

        $this->publishes([
            __DIR__ . '/../database/migrations/2025_07_02_000000_create_permission_tables.php' => database_path(
                'migrations/2025_07_02_000000_create_permission_tables.php'
            ),
        ], 'permission-migrations');
    }
}
