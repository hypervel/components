<?php

declare(strict_types=1);

namespace Hypervel\Bus;

use Hypervel\Container\Container;
use Hypervel\Contracts\Bus\Dispatcher as DispatcherContract;
use Hypervel\Contracts\Bus\QueueingDispatcher as QueueingDispatcherContract;
use Hypervel\Contracts\Queue\Factory as QueueFactoryContract;
use Hypervel\Contracts\Redis\Factory as RedisFactory;
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
            DispatcherContract::class,
        );

        $this->app->alias(
            DispatcherContract::class,
            QueueingDispatcherContract::class,
        );
    }

    /**
     * Register the batch handling services.
     */
    protected function registerBatchServices(): void
    {
        $arguments = static function ($app): array {
            $config = $app->make('config');

            return [
                $app->make(BatchFactory::class),
                $app->make('db'),
                $config->string('queue.batching.table'),
                $config->get('queue.batching.database'),
                $config->string('queue.batching.results_table'),
            ];
        };

        $this->app->singleton(BatchRepository::class, static fn ($app) => new RoutingBatchRepository(
            ...$arguments($app),
            container: $app,
            config: $app->make('config'),
        ));
        $this->app->singleton(DatabaseBatchRepository::class, static fn ($app) => new DatabaseBatchRepository(...$arguments($app)));
        $this->app->singleton(RedisBatchRepository::class, static fn ($app) => new RedisBatchRepository(
            ...$arguments($app),
            redis: $app->make(RedisFactory::class),
            redisConnection: $app->make('config')->string('queue.batching.redis_connection'),
            deletionsTable: $app->make('config')->string('queue.batching.deletions_table'),
        ));
        // DynamoDB batch storage is intentionally unsupported because Hypervel does not support DynamoDB databases.
    }
}
