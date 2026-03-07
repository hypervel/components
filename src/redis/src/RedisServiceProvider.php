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

        // bind(), not singleton() — RedisFactory already caches the RedisProxy, so this
        // just resolves through the current manager state without adding a redundant cache
        // layer that would need its own invalidation when RedisFactory is rebuilt.
        $this->app->bind('redis.connection', fn ($app) => $app['redis']->connection());

        $this->app->singleton(\Redis::class, Redis::class);
    }
}
