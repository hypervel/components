<?php

declare(strict_types=1);

namespace Hypervel\Bus;

use Hypervel\Container\Container;
use Hypervel\Contracts\Bus\Dispatcher as DispatcherContract;
use Hypervel\Contracts\Bus\QueueingDispatcher as QueueingDispatcherContract;
use Hypervel\Contracts\Queue\Factory as QueueFactoryContract;
use Hypervel\Support\ServiceProvider;

class BusServiceProvider extends ServiceProvider
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
            DispatcherContract::class
        );

        $this->app->alias(
            Dispatcher::class,
            QueueingDispatcherContract::class
        );
    }

    /**
     * Register the batch handling services.
     */
    protected function registerBatchServices(): void
    {
        $this->app->singleton(BatchRepository::class, DatabaseBatchRepository::class);

        $this->app->singleton(DatabaseBatchRepository::class, function ($app) {
            return new DatabaseBatchRepository(
                $app->make(BatchFactory::class),
                $app->make(\Hypervel\Database\ConnectionResolverInterface::class),
                $app->make('config')->get('queue.batching.table', 'job_batches'),
                $app->make('config')->get('queue.batching.database'),
            );
        });
    }
}
