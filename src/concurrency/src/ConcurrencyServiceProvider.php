<?php

declare(strict_types=1);

namespace Hypervel\Concurrency;

use Hypervel\Contracts\Foundation\ReloadsConfiguration;
use Hypervel\Support\ServiceProvider;

class ConcurrencyServiceProvider extends ServiceProvider implements ReloadsConfiguration
{
    /**
     * Register the service provider.
     */
    public function register(): void
    {
        $this->app->singleton(ConcurrencyManager::class, fn ($app) => new ConcurrencyManager($app));
    }

    /**
     * Reload configuration-derived worker state.
     *
     * Boot-only. Request-time use clears shared concurrency drivers while
     * concurrent coroutines may still be using them.
     */
    public function reloadConfiguration(): void
    {
        if ($this->app->resolved(ConcurrencyManager::class)) {
            $this->app->make(ConcurrencyManager::class)->forgetInstances();
        }
    }
}
