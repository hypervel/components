<?php

declare(strict_types=1);

namespace Hypervel\Tinker;

use Hypervel\Support\ServiceProvider;
use Hypervel\Tinker\Console\TinkerCommand;

class TinkerServiceProvider extends ServiceProvider
{
    /**
     * Register the service provider.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            dirname(__DIR__) . '/config/tinker.php',
            'tinker'
        );

        $this->commands([TinkerCommand::class]);
    }

    /**
     * Bootstrap the service provider.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                dirname(__DIR__) . '/config/tinker.php' => config_path('tinker.php'),
            ], 'tinker-config');
        }
    }
}
