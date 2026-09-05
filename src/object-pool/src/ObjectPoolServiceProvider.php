<?php

declare(strict_types=1);

namespace Hypervel\ObjectPool;

use Hypervel\Core\Events\AfterWorkerStart;
use Hypervel\Core\Events\OnWorkerExit;
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

        $events->listen(AfterWorkerStart::class, function (AfterWorkerStart $event): void {
            $this->app->make(StartRecycler::class)->handle($event);
        });

        $events->listen(OnWorkerExit::class, function (OnWorkerExit $event): void {
            if ($this->app->resolved(PoolManager::class)) {
                $this->app->make(PoolManager::class)->flush();
            }
        });
    }
}
