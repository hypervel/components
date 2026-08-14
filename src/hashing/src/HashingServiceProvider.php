<?php

declare(strict_types=1);

namespace Hypervel\Hashing;

use Hypervel\Contracts\Foundation\ReloadsConfiguration;
use Hypervel\Support\ServiceProvider;

class HashingServiceProvider extends ServiceProvider implements ReloadsConfiguration
{
    /**
     * Register the service provider.
     */
    public function register(): void
    {
        $this->app->singleton('hash', fn ($app) => new HashManager($app));

        $this->app->singleton('hash.driver', fn ($app) => $app->make('hash')->driver());
    }

    /**
     * Reload configuration-derived worker state.
     *
     * Boot-only. Request-time use clears shared hash drivers while concurrent
     * coroutines may still be using them.
     */
    public function reloadConfiguration(): void
    {
        if ($this->app->resolved('hash')) {
            $this->app->make('hash')->forgetDrivers();
        }

        $this->app->forgetInstance('hash.driver');
    }
}
