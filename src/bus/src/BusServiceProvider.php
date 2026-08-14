<?php

declare(strict_types=1);

namespace Hypervel\Bus;

use Hypervel\Container\Container;
use Hypervel\Contracts\Bus\Dispatcher as DispatcherContract;
use Hypervel\Contracts\Bus\QueueingDispatcher as QueueingDispatcherContract;
use Hypervel\Contracts\Foundation\ReloadsConfiguration;
use Hypervel\Contracts\Queue\Factory as QueueFactoryContract;
use Hypervel\Support\ServiceProvider;

class BusServiceProvider extends ServiceProvider implements ReloadsConfiguration
{
    /**
     * Register the service provider.
     */
    public function register(): void
    {
        $this->app->singleton(Dispatcher::class, function ($app) {
            return new Dispatcher($app, function (?string $connection = null) {
                return Container::getInstance()->make(QueueFactoryContract::class)->connection($connection);
            });
        });

        $this->registerBatchServices();

        $this->app->alias(
            Dispatcher::class,
            DispatcherContract::class,
        );

        $this->app->alias(
            DispatcherContract::class,
            QueueingDispatcherContract::class,
        );
    }

    /**
     * Reload configuration-derived worker state.
     *
     * Boot-only. Request-time use replaces shared batch repositories while
     * concurrent coroutines may still hold the previous instances.
     */
    public function reloadConfiguration(): void
    {
        $this->app->forgetInstance(BatchRepository::class);
        $this->app->forgetInstance(DatabaseBatchRepository::class);
    }

    /**
     * Register the batch handling services.
     */
    protected function registerBatchServices(): void
    {
        $this->app->singleton(BatchRepository::class, function ($app) {
            return $app->make(DatabaseBatchRepository::class);
        });

        // DynamoDB batch storage is intentionally unsupported because Hypervel does not support DynamoDB databases.

        $this->app->singleton(DatabaseBatchRepository::class, function ($app) {
            return new DatabaseBatchRepository(
                $app->make(BatchFactory::class),
                $app->make('db'),
                $app->make('config')->string('queue.batching.table'),
                $app->make('config')->get('queue.batching.database'),
            );
        });
    }
}
