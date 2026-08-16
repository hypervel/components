<?php

declare(strict_types=1);

namespace Hypervel\Session;

use Hypervel\Contracts\Foundation\ReloadsConfiguration;
use Hypervel\Support\ServiceProvider;

class SessionServiceProvider extends ServiceProvider implements ReloadsConfiguration
{
    /**
     * Register the service provider.
     */
    public function register(): void
    {
        $this->registerSessionManager();
        $this->registerSessionDriver();

        $this->commands([
            Console\SessionTableCommand::class,
        ]);
    }

    /**
     * Reload configuration-derived worker state.
     *
     * Boot-only. Request-time use replaces shared session state while
     * concurrent coroutines may still hold the previous store.
     */
    public function reloadConfiguration(): void
    {
        if ($this->app->resolved('session')) {
            $this->app->make('session')->forgetDrivers();
        }

        $this->app->forgetInstance('session.store');

        if ($this->app->resolved('redirect')) {
            $this->app->make('redirect')->setSession($this->app->make('session.store'));
        }
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
