<?php

declare(strict_types=1);

namespace Hypervel\Redis;

use Hypervel\Core\Events\BeforeServerFork;
use Hypervel\Core\Events\BeforeWorkerStart;
use Hypervel\Core\Events\TaskTerminated;
use Hypervel\Redis\Listeners\RedisConnectionLifecycleListener;
use Hypervel\Redis\Pool\PoolFactory;
use Hypervel\Support\ServiceProvider;
use Swoole\Constant;

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
            $app->make(RedisConfig::class),
            $app->make(RedisSentinelFactory::class),
        ));

        $this->app->bind('redis.connection', fn ($app) => $app->make('redis')->connection());
    }

    /**
     * Bootstrap the service provider.
     */
    public function boot(): void
    {
        $events = $this->app->make('events');
        $listener = fn (): RedisConnectionLifecycleListener => $this->app->make(
            RedisConnectionLifecycleListener::class
        );

        if (! $this->app->make('config')->boolean(
            'server.settings.' . Constant::OPTION_TASK_ENABLE_COROUTINE
        )) {
            $events->listen(TaskTerminated::class, function () use ($listener): void {
                $listener()->releaseTaskConnections();
            });
        }

        $events->listen(BeforeServerFork::class, function () use ($listener): void {
            $listener()->discardProcessConnections();
        });

        $events->listen(BeforeWorkerStart::class, function () use ($listener): void {
            $listener()->discardProcessConnections();
        });
    }
}
