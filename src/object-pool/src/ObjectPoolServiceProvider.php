<?php

declare(strict_types=1);

namespace Hypervel\ObjectPool;

use Hypervel\Framework\Events\AfterWorkerStart;
use Hypervel\ObjectPool\Contracts\Factory;
use Hypervel\ObjectPool\Contracts\Recycler;
use Hypervel\ObjectPool\Listeners\StartRecycler;
use Hypervel\Support\ServiceProvider;

class ObjectPoolServiceProvider extends ServiceProvider
{
    /**
     * Register the service provider.
     */
    public function register(): void
    {
        $this->app->singleton(Factory::class, PoolManager::class);

        $this->app->singleton(Recycler::class, ObjectRecycler::class);
    }

    /**
     * Bootstrap the service provider.
     */
    public function boot(): void
    {
        $events = $this->app->make('events');

        $events->listen(AfterWorkerStart::class, function (AfterWorkerStart $event) {
            $this->app->make(StartRecycler::class)->process($event);
        });
    }
}
