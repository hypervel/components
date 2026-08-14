<?php

declare(strict_types=1);

namespace Hypervel\Cache;

use Hypervel\Cache\Console\CacheTableCommand;
use Hypervel\Cache\Console\ClearCommand;
use Hypervel\Cache\Console\ForgetCommand;
use Hypervel\Cache\Console\PruneDbExpiredCommand;
use Hypervel\Cache\Console\PruneStaleTagsCommand;
use Hypervel\Cache\Listeners\CreateSwooleTable;
use Hypervel\Cache\Listeners\RegisterSwooleMaintenanceTimers;
use Hypervel\Cache\Redis\Console\BenchmarkCommand;
use Hypervel\Cache\Redis\Console\DoctorCommand;
use Hypervel\Contracts\Foundation\ReloadsConfiguration;
use Hypervel\Core\Events\AfterWorkerStart;
use Hypervel\Core\Events\BeforeServerStart;
use Hypervel\Support\ServiceProvider;

class CacheServiceProvider extends ServiceProvider implements ReloadsConfiguration
{
    /**
     * Register the service provider.
     */
    public function register(): void
    {
        $this->app->singleton('cache', fn ($app) => new CacheManager($app));

        $this->app->singleton('cache.store', fn ($app) => $app->make('cache')->driver());

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
     * Reload configuration-derived worker state.
     *
     * Boot-only. Request-time use clears shared cache stores while concurrent
     * coroutines may still be using them.
     */
    public function reloadConfiguration(): void
    {
        if ($this->app->resolved('cache')) {
            $this->app->make('cache')->forgetDrivers();
        }

        $this->app->forgetInstance('cache.store');
    }

    /**
     * Bootstrap the service provider.
     */
    public function boot(): void
    {
        $events = $this->app->make('events');

        $events->listen(BeforeServerStart::class, function (BeforeServerStart $event): void {
            $this->app->make(CreateSwooleTable::class)->handle($event);
        });

        $events->listen(AfterWorkerStart::class, function (AfterWorkerStart $event): void {
            $this->app->make(RegisterSwooleMaintenanceTimers::class)->handle($event);
        });

        if ($this->app->runningInConsole()) {
            $cache = $this->app->make(CacheManager::class);

            $this->app->booted(
                fn () => $cache->finalizeSerializableClasses(),
            );

            return;
        }

        // Worker configuration is reloaded during BeforeWorkerStart.
        $events->listen(AfterWorkerStart::class, function (AfterWorkerStart $event): void {
            $this->app->make(CacheManager::class)->finalizeSerializableClasses();
        });
    }
}
