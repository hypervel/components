<?php

declare(strict_types=1);

namespace Hypervel\Notifications;

use Hypervel\Contracts\Notifications\Dispatcher as DispatcherContract;
use Hypervel\Contracts\Notifications\Factory as FactoryContract;
use Hypervel\Support\ServiceProvider;

class NotificationServiceProvider extends ServiceProvider
{
    /**
     * Register the service provider.
     */
    public function register(): void
    {
        $this->app->singleton(ChannelManager::class);

        $this->app->alias(ChannelManager::class, DispatcherContract::class);
        $this->app->alias(ChannelManager::class, FactoryContract::class);
    }

    /**
     * Bootstrap the service provider.
     */
    public function boot(): void
    {
        $this->loadViewsFrom(dirname(__DIR__) . '/publish/resources/views', 'notifications');
    }
}
