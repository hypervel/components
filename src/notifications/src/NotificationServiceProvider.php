<?php

declare(strict_types=1);

namespace Hypervel\Notifications;

use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Notifications\Dispatcher as DispatcherContract;
use Hypervel\Contracts\Notifications\Factory as FactoryContract;
use Hypervel\Notifications\Events\NotificationFailed;
use Hypervel\Support\ServiceProvider;

class NotificationServiceProvider extends ServiceProvider
{
    /**
     * Register the service provider.
     */
    public function register(): void
    {
        $this->app->singleton(ChannelManager::class, fn ($app) => new ChannelManager($app));

        $this->app->alias(ChannelManager::class, DispatcherContract::class);
        $this->app->alias(ChannelManager::class, FactoryContract::class);

        $this->commands([
            Console\NotificationTableCommand::class,
        ]);
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

        // Register once at boot — persists in the dispatcher's $listeners for the worker lifetime.
        // Writes to coroutine-local Context so concurrent requests don't interfere.
        // See NotificationSender::sendToNotifiable() for the consumer of this flag.
        $this->app->make('events')->listen(
            NotificationFailed::class,
            static fn () => CoroutineContext::set(NotificationSender::FAILED_EVENT_DISPATCHED_CONTEXT_KEY, true)
        );
    }
}
