<?php

declare(strict_types=1);

namespace Hypervel\Broadcasting;

use Hypervel\Contracts\Broadcasting\Broadcaster as BroadcasterContract;
use Hypervel\Contracts\Broadcasting\Factory as BroadcastingFactory;
use Hypervel\Contracts\Foundation\ReloadsConfiguration;
use Hypervel\Support\ServiceProvider;

class BroadcastServiceProvider extends ServiceProvider implements ReloadsConfiguration
{
    /**
     * Register the service provider.
     */
    public function register(): void
    {
        $this->app->singleton(BroadcasterContract::class, function ($app) {
            return $app->make(BroadcastManager::class)->connection();
        });

        $this->app->alias(
            BroadcastManager::class,
            BroadcastingFactory::class
        );
    }

    /**
     * Reload configuration-derived worker state.
     *
     * Boot-only. Request-time use clears shared broadcast connections while
     * concurrent coroutines may still be using them.
     */
    public function reloadConfiguration(): void
    {
        if ($this->app->resolved(BroadcastManager::class)) {
            $this->app->make(BroadcastManager::class)->forgetDrivers();
        }

        $this->app->forgetInstance(BroadcasterContract::class);
    }
}
