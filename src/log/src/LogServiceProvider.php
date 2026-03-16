<?php

declare(strict_types=1);

namespace Hypervel\Log;

use Hypervel\Support\ServiceProvider;

class LogServiceProvider extends ServiceProvider
{
    /**
     * Register the service provider.
     */
    public function register(): void
    {
        $this->app->singleton('log', fn ($app) => new LogManager($app));
    }
}
