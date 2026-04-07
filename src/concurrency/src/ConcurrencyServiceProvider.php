<?php

declare(strict_types=1);

namespace Hypervel\Concurrency;

use Hypervel\Support\ServiceProvider;

class ConcurrencyServiceProvider extends ServiceProvider
{
    /**
     * Register the service provider.
     */
    public function register(): void
    {
        $this->app->singleton(ConcurrencyManager::class, fn ($app) => new ConcurrencyManager($app));
    }
}
