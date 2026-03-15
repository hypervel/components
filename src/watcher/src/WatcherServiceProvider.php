<?php

declare(strict_types=1);

namespace Hypervel\Watcher;

use Hypervel\Support\ServiceProvider;
use Hypervel\Watcher\Console\WatchCommand;
use Hypervel\Watcher\Events\BeforeServerRestart;
use Hypervel\Watcher\Listeners\ReloadDotenvListener;

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
        $this->publishes([
            __DIR__ . '/../config/watcher.php' => $this->app->configPath('watcher.php'),
        ]);

        $this->app->make('events')
            ->listen(BeforeServerRestart::class, ReloadDotenvListener::class);
    }
}
