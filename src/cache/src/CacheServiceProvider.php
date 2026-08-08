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
use Hypervel\Core\Events\AfterWorkerStart;
use Hypervel\Core\Events\BeforeServerStart;
use Hypervel\Support\ServiceProvider;

class CacheServiceProvider extends ServiceProvider
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
