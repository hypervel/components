<?php

declare(strict_types=1);

namespace Hypervel\Auth\Passwords;

use Hypervel\Contracts\Foundation\ReloadsConfiguration;
use Hypervel\Support\ServiceProvider;

class PasswordResetServiceProvider extends ServiceProvider implements ReloadsConfiguration
{
    /**
     * Register the service provider.
     */
    public function register(): void
    {
        $this->registerPasswordBroker();
        $this->registerEventRebindHandler();
    }

    /**
     * Reload configuration-derived worker state.
     *
     * Boot-only. Request-time use clears shared resolved brokers while
     * concurrent coroutines may still be using them.
     */
    public function reloadConfiguration(): void
    {
        if ($this->app->resolved('auth.password')) {
            $this->app->make('auth.password')->forgetBrokers();
        }
    }

    /**
     * Register the password broker instance.
     */
    protected function registerPasswordBroker(): void
    {
        $this->app->singleton('auth.password', fn ($app) => new PasswordBrokerManager($app));

        // bind() so the alias reflects the current coroutine's default broker.
        // The closure just asks the singleton manager for its cached broker.
        $this->app->bind('auth.password.broker', fn ($app) => $app->make('auth.password')->broker());
    }

    /**
     * Handle the re-binding of the event dispatcher binding.
     */
    protected function registerEventRebindHandler(): void
    {
        $this->app->rebinding('events', function ($app, $dispatcher): void {
            if (! $app->resolved('auth.password')) {
                return;
            }

            $app->make('auth.password')->refreshEventDispatcher($dispatcher);
        });
    }
}
