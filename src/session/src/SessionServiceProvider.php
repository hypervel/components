<?php

declare(strict_types=1);

namespace Hypervel\Session;

use Hypervel\Support\ServiceProvider;

class SessionServiceProvider extends ServiceProvider
{
    /**
     * Register the service provider.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/session.php', 'session');

        $this->registerSessionManager();
        $this->registerSessionDriver();
    }

    /**
     * Bootstrap the service provider.
     */
    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/session.php' => config_path('session.php'),
        ], 'session-config');
    }

    /**
     * Register the session manager instance.
     */
    protected function registerSessionManager(): void
    {
        $this->app->singleton('session', fn ($app) => new SessionManager($app));
    }

    /**
     * Register the session driver instance.
     */
    protected function registerSessionDriver(): void
    {
        $this->app->singleton('session.store', fn ($app) => $app->make('session')->driver());
    }
}
