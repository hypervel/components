<?php

declare(strict_types=1);

namespace Hypervel\Session;

use Hypervel\Session\Middleware\StartSession;
use Hypervel\Support\ServiceProvider;

class SessionServiceProvider extends ServiceProvider
{
    /**
     * Register the service provider.
     */
    public function register(): void
    {
        $this->registerSessionManager();
        $this->registerSessionDriver();

        $this->app->singleton(StartSession::class);

        $this->commands([
            Console\SessionTableCommand::class,
        ]);
    }

    /**
     * Bootstrap the service provider.
     */
    public function boot(): void
    {
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
