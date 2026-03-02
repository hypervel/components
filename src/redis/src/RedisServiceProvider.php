<?php

declare(strict_types=1);

namespace Hypervel\Redis;

use Hypervel\Redis\Pool\PoolFactory;
use Hypervel\Support\ServiceProvider;

class RedisServiceProvider extends ServiceProvider
{
    /**
     * Register the service provider.
     */
    public function register(): void
    {
        $this->app->singleton('redis', fn ($app) => new Redis($app->make(PoolFactory::class)));

        $this->app->singleton(\Redis::class, Redis::class);
    }
}
