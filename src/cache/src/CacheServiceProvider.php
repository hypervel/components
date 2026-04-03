<?php

declare(strict_types=1);

namespace Hypervel\Cache;

use Hypervel\Cache\Console\CacheTableCommand;
use Hypervel\Cache\Console\ClearCommand;
use Hypervel\Cache\Console\ForgetCommand;
use Hypervel\Cache\Console\PruneDbExpiredCommand;
use Hypervel\Cache\Console\PruneStaleTagsCommand;
use Hypervel\Cache\Listeners\CreateSwooleTable;
use Hypervel\Cache\Listeners\CreateTimer;
use Hypervel\Cache\Redis\Console\BenchmarkCommand;
use Hypervel\Cache\Redis\Console\DoctorCommand;
use Hypervel\Framework\Events\BeforeServerStart;
use Hypervel\Framework\Events\OnManagerStart;
use Hypervel\Support\ServiceProvider;

class CacheServiceProvider extends ServiceProvider
{
    /**
     * Register the service provider.
     */
    public function register(): void
    {
        $this->app->singleton('cache', fn ($app) => new CacheManager($app));

        $this->app->singleton('cache.store', fn ($app) => $app['cache']->driver());

        $this->app->singleton(RateLimiter::class, fn ($app) => new RateLimiter(
            $app->make('cache')->driver(
                $app['config']->get('cache.limiter')
            )
        ));

        $this->commands([
            BenchmarkCommand::class,
            CacheTableCommand::class,
            ClearCommand::class,
            DoctorCommand::class,
            ForgetCommand::class,
            PruneDbExpiredCommand::class,
            PruneStaleTagsCommand::class,
        ]);
    }

    /**
     * Bootstrap the service provider.
     */
    public function boot(): void
    {
        $events = $this->app->make('events');

        $events->listen(BeforeServerStart::class, function (BeforeServerStart $event) {
            $this->app->make(CreateSwooleTable::class)->handle($event);
        });

        $events->listen(OnManagerStart::class, function (OnManagerStart $event) {
            $this->app->make(CreateTimer::class)->handle($event);
        });
    }
}
