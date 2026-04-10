<?php

declare(strict_types=1);

namespace Hypervel\Redis;

use Hypervel\Redis\Pool\PoolFactory;
use Hypervel\Support\ServiceProvider;
use Redis;

class RedisServiceProvider extends ServiceProvider
{
    /**
     * Register the service provider.
     */
    public function register(): void
    {
        $this->app->singleton('redis', fn ($app) => new RedisManager(
            $app,
            $app->make(PoolFactory::class),
            $app->make(RedisConfig::class)
        ));

        $this->app->bind('redis.connection', fn ($app) => $app['redis']->connection());

        $this->app->singleton(Redis::class, RedisManager::class);
    }
}
