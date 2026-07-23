<?php

declare(strict_types=1);

namespace Hypervel\Watcher;

use Hypervel\Support\ServiceProvider;
use Hypervel\Watcher\Console\WatchCommand;

class WatcherServiceProvider extends ServiceProvider
{
    /**
     * Register the service provider.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/watcher.php', 'watcher');

        $this->commands([
            WatchCommand::class,
        ]);
    }

    /**
     * Bootstrap the service provider.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/watcher.php' => $this->app->configPath('watcher.php'),
            ], 'watcher-config');
        }
    }
}
