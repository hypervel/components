<?php

declare(strict_types=1);

namespace Hypervel\Notifications;

use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Foundation\ReloadsConfiguration;
use Hypervel\Contracts\Notifications\Dispatcher as DispatcherContract;
use Hypervel\Contracts\Notifications\Factory as FactoryContract;
use Hypervel\Notifications\Channels\MailChannel;
use Hypervel\Notifications\Events\NotificationFailed;
use Hypervel\Support\ServiceProvider;

class NotificationServiceProvider extends ServiceProvider implements ReloadsConfiguration
{
    /**
     * Register the service provider.
     */
    public function register(): void
    {
        $this->app->alias(ChannelManager::class, DispatcherContract::class);
        $this->app->alias(ChannelManager::class, FactoryContract::class);

        $this->commands([
            Console\NotificationTableCommand::class,
        ]);
    }

    /**
     * Reload configuration-derived worker state.
     *
     * Boot-only. Request-time use clears shared notification channels while
     * concurrent coroutines may still be using them.
     */
    public function reloadConfiguration(): void
    {
        if ($this->app->resolved(ChannelManager::class)) {
            $this->app->make(ChannelManager::class)->forgetDrivers();
        }

        $this->app->forgetInstance(MailChannel::class);
    }

    /**
     * Bootstrap the service provider.
     */
    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'notifications');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../resources/views' => resource_path('views/vendor/notifications'),
            ], 'hypervel-notifications');
        }

        $this->app->make('events')->listen(
            NotificationFailed::class,
            static function (): void {
                // Only an active sender attempt owns this marker; external events must not create it.
                if (CoroutineContext::get(NotificationSender::FAILED_EVENT_DISPATCHED_CONTEXT_KEY) !== null) {
                    CoroutineContext::set(NotificationSender::FAILED_EVENT_DISPATCHED_CONTEXT_KEY, true);
                }
            }
        );
    }
}
