<?php

declare(strict_types=1);

namespace Hypervel\Sanctum;

use Hypervel\Sanctum\Console\Commands\PruneExpired;
use Hypervel\Support\Facades\Route;
use Hypervel\Support\ServiceProvider;

class SanctumServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any package services.
     */
    public function boot(): void
    {
        $this->registerCommands();
        $this->registerPublishing();
        $this->registerRoutes();
    }

    /**
     * Register the package routes.
     */
    protected function registerRoutes(): void
    {
        Route::group(
            '/',
            __DIR__ . '/../routes/web.php',
            [
                'namespace' => 'Hypervel\Sanctum\Http\Controllers',
            ]
        );
    }

    /**
     * Register the package's publishable resources.
     */
    protected function registerPublishing(): void
    {
        $this->publishes([
            __DIR__ . '/../publish/sanctum.php' => config_path('sanctum.php'),
        ], 'sanctum-config');

        $this->publishes([
            __DIR__ . '/../database/migrations' => database_path('migrations'),
        ], 'sanctum-migrations');
    }

    /**
     * Register the package's commands.
     */
    protected function registerCommands(): void
    {
        $this->commands([
            PruneExpired::class,
        ]);
    }

    /**
     * Register any package services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../publish/sanctum.php',
            'sanctum'
        );
    }
}