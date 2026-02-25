<?php

declare(strict_types=1);

namespace Hypervel\Hashing;

use Hypervel\Support\ServiceProvider;

class HashingServiceProvider extends ServiceProvider
{
    /**
     * Register the service provider.
     */
    public function register(): void
    {
        $this->app->singleton('hash', fn ($app) => new HashManager($app));

        $this->app->singleton('hash.driver', fn ($app) => $app->make('hash')->driver());
    }
}
