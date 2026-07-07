<?php

declare(strict_types=1);

namespace Hypervel\Auth\Passwords;

use Hypervel\Support\ServiceProvider;

class PasswordResetServiceProvider extends ServiceProvider
{
    /**
     * Register the service provider.
     */
    public function register(): void
    {
        $this->registerPasswordBroker();
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
}
