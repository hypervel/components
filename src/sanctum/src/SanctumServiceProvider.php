<?php

declare(strict_types=1);

namespace Hypervel\Sanctum;

use Hyperf\HttpServer\Contract\RequestInterface;
use Hypervel\Auth\AuthManager;
use Hypervel\Contracts\Config\Repository;
use Hypervel\Sanctum\Console\Commands\PruneExpired;
use Hypervel\Support\Facades\Route;
use Hypervel\Support\ServiceProvider;
use Psr\EventDispatcher\EventDispatcherInterface;

use function Hypervel\Config\config;

class SanctumServiceProvider extends ServiceProvider
{
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
                $request = $this->app->get(RequestInterface::class);

                // Get the provider
                $provider = $authManager->createUserProvider($config['provider'] ?? null);

                // Get event dispatcher if available
                $events = null;
                if ($this->app->has(EventDispatcherInterface::class)) {
                    $events = $this->app->get(EventDispatcherInterface::class);
                }

                // Get expiration from sanctum config
                $expiration = $this->app->get(Repository::class)->get('sanctum.expiration');

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
        Route::group(
            '/',
            __DIR__ . '/../routes/web.php',
            [
                'middleware' => config('sanctum.middleware', 'web'),
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
     * Register the console commands for the package.
     */
    protected function registerCommands(): void
    {
        $this->commands([
            PruneExpired::class,
        ]);
    }
}
