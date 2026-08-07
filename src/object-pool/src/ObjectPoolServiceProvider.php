<?php

declare(strict_types=1);

namespace Hypervel\ObjectPool;

use Hypervel\Core\Events\AfterWorkerStart;
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
        $this->app->alias(PoolManager::class, Factory::class);

        $this->app->alias(PoolRecycler::class, Recycler::class);
    }

    /**
     * Bootstrap the service provider.
     */
    public function boot(): void
    {
        $events = $this->app->make('events');

        $events->listen(AfterWorkerStart::class, function (AfterWorkerStart $event) {
            $this->app->make(StartRecycler::class)->handle($event);
        });
    }
}
