<?php

declare(strict_types=1);

namespace Hypervel\Events;

use Hypervel\Contracts\Queue\Factory as QueueFactoryContract;
use Hypervel\Support\ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    /**
     * Register the service provider.
     */
    public function register(): void
    {
        $this->app->singleton('events', function ($app) {
            return (new Dispatcher($app))->setQueueResolver(function () {
                return $this->app->make(QueueFactoryContract::class);
            })->setTransactionManagerResolver(function () {
                return $this->app->bound('db.transactions')
                    ? $this->app->make('db.transactions')
                    : null;
            });
        });
    }
}
