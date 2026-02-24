<?php

declare(strict_types=1);

namespace Hypervel\Broadcasting;

use Hypervel\Contracts\Broadcasting\Broadcaster as BroadcasterContract;
use Hypervel\Contracts\Broadcasting\Factory as BroadcastingFactory;
use Hypervel\Support\ServiceProvider;

class BroadcastServiceProvider extends ServiceProvider
{
    /**
     * Register the service provider.
     */
    public function register(): void
    {
        $this->app->singleton(BroadcastManager::class, fn ($app) => new BroadcastManager($app));

        $this->app->singleton(BroadcasterContract::class, function ($app) {
            return $app->make(BroadcastManager::class)->connection();
        });

        $this->app->alias(
            BroadcastManager::class, BroadcastingFactory::class
        );
    }
}
