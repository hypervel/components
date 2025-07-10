<?php

declare(strict_types=1);

namespace Hypervel\Permission;

use Hypervel\Permission\Contracts\Factory;
use Hypervel\Support\ServiceProvider;

class PermissionServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->registerPublishing();
        $this->registerPermissionManager();
        $this->registerCommands();
    }

    /**
     * Register any package services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/permission.php',
            'permission'
        );
    }

    protected function registerPermissionManager(): void
    {
        $this->app->bind(
            Factory::class,
            fn ($container) => $container->get(PermissionManager::class)
        );
    }

    public function registerPublishing(): void
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

    /**
     * Register the package's commands.
     */
    protected function registerCommands(): void
    {
        $this->commands([
            Console\ShowCommand::class,
        ]);
    }
}
