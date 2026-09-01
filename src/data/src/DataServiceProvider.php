<?php

declare(strict_types=1);

namespace Hypervel\Data;

use Hypervel\Contracts\Config\Repository;
use Hypervel\Contracts\Container\Container;
use Hypervel\Data\Support\DataConfig;
use Hypervel\Support\ServiceProvider;

class DataServiceProvider extends ServiceProvider
{
    /**
     * Register data services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            dirname(__DIR__) . '/config/data.php',
            'data',
        );

        $this->app->singleton(
            DataConfig::class,
            fn (Container $container): DataConfig => new DataConfig(
                $container->make(Repository::class),
            ),
        );
    }

    /**
     * Bootstrap data services.
     */
    public function boot(): void
    {
        $this->app->make(DataConfig::class);

        if ($this->app->runningInConsole()) {
            $this->publishes([
                dirname(__DIR__) . '/config/data.php' => config_path('data.php'),
            ], 'data-config');
        }
    }
}
