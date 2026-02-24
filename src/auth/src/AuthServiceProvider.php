<?php

declare(strict_types=1);

namespace Hypervel\Auth;

use Hypervel\Auth\Access\Gate;
use Hypervel\Contracts\Auth\Access\Gate as GateContract;
use Hypervel\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Hypervel\Support\ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * Register the service provider.
     */
    public function register(): void
    {
        $this->registerAuthenticator();
        $this->registerUserResolver();
        $this->registerAccessGate();
    }

    /**
     * Register the authenticator services.
     */
    protected function registerAuthenticator(): void
    {
        $this->app->singleton('auth', fn ($app) => new AuthManager($app));

        $this->app->singleton('auth.driver', fn ($app) => $app['auth']->guard());
    }

    /**
     * Register a resolver for the authenticated user.
     */
    protected function registerUserResolver(): void
    {
        $this->app->bind(AuthenticatableContract::class, fn ($app) => call_user_func($app['auth']->userResolver()));
    }

    /**
     * Register the access gate service.
     */
    protected function registerAccessGate(): void
    {
        $this->app->singleton(GateContract::class, function ($app) {
            return new Gate($app, fn () => call_user_func($app['auth']->userResolver()));
        });
    }

    /**
     * Bootstrap the service provider.
     */
    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../publish/auth.php' => BASE_PATH . '/config/autoload/auth.php',
        ]);
    }
}
