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
        $this->mergeConfigFrom(__DIR__ . '/../config/logging.php', 'logging');

        $this->app->singleton('log', fn ($app) => new LogManager($app));
    }

    /**
     * Bootstrap the service provider.
     */
    public function boot(): void
    {
        $this->publishesConfig([
            __DIR__ . '/../config/logging.php' => config_path('logging.php'),
        ], 'logging-config');
    }
}
