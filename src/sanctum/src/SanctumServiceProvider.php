<?php

declare(strict_types=1);

namespace Hypervel\Sanctum;

use Hypervel\Auth\AuthManager;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Http\Request;
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
        $this->registerPublishing();
        $this->registerRoutes();
        $this->registerCommands();
    }

    /**
     * Register the Sanctum authentication guard.
     */
    protected function registerSanctumGuard(): void
    {
        $this->callAfterResolving(AuthManager::class, function (AuthManager $authManager) {
            $authManager->extend('sanctum', function ($name, $config) use ($authManager) {
                $request = $this->app->make(Request::class);

                // Get the provider
                $provider = $authManager->createUserProvider($config['provider'] ?? null);

                // Get event dispatcher if available
                $events = null;
                if ($this->app->has(Dispatcher::class)) {
                    $events = $this->app->make(Dispatcher::class);
                }

                // Get expiration from sanctum config
                $expiration = $this->app->make('config')->get('sanctum.expiration');

                return new SanctumGuard(
                    name: $name,
                    provider: $provider,
                    request: $request,
                    events: $events,
                    expiration: $expiration
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
