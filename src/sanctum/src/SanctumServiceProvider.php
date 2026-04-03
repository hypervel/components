<?php

declare(strict_types=1);

namespace Hypervel\Sanctum;

use Hypervel\Auth\AuthManager;
use Hypervel\Sanctum\Console\Commands\PruneExpired;
use Hypervel\Support\Facades\Route;
use Hypervel\Support\ServiceProvider;

use function Hypervel\Config\config;

class SanctumServiceProvider extends ServiceProvider
{
    /**
     * Register any package services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/sanctum.php',
            'sanctum'
        );
    }

    /**
     * Bootstrap any package services.
     */
    public function boot(): void
    {
        $this->registerSanctumGuard();
        if ($this->app->runningInConsole()) {
            $this->registerPublishing();
            $this->registerCommands();
        }

        $this->registerRoutes();
    }

    /**
     * Register the Sanctum authentication guard.
     */
    protected function registerSanctumGuard(): void
    {
        $this->callAfterResolving(AuthManager::class, function (AuthManager $authManager) {
            $authManager->extend('sanctum', function ($app, $name, $config) use ($authManager) {
                return new SanctumGuard(
                    name: $name,
                    provider: $authManager->createUserProvider($config['provider'] ?? null),
                    app: $app,
                    events: $app->has('events') ? $app['events'] : null,
                    expiration: $app['config']->get('sanctum.expiration'),
                );
            });
        });
    }

    /**
     * Register the package routes.
     */
    protected function registerRoutes(): void
    {
        Route::middleware(config('sanctum.middleware', 'web'))
            ->group(__DIR__ . '/../routes/web.php');
    }

    /**
     * Register the package's publishable resources.
     */
    protected function registerPublishing(): void
    {
        $this->publishes([
            __DIR__ . '/../config/sanctum.php' => config_path('sanctum.php'),
        ], 'sanctum-config');

        $this->publishes([
            __DIR__ . '/../database/migrations' => database_path('migrations'),
        ], 'sanctum-migrations');
    }

    /**
     * Register the console commands for the package.
     */
    protected function registerCommands(): void
    {
        $this->commands([
            PruneExpired::class,
        ]);
    }
}
