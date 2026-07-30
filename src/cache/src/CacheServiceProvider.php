<?php

declare(strict_types=1);

namespace Hypervel\Cache;

use Hypervel\Cache\Console\CacheTableCommand;
use Hypervel\Cache\Console\ClearCommand;
use Hypervel\Cache\Console\ForgetCommand;
use Hypervel\Cache\Console\PruneDbExpiredCommand;
use Hypervel\Cache\Console\PruneStaleTagsCommand;
use Hypervel\Cache\Listeners\CreateSwooleTable;
use Hypervel\Cache\Listeners\CreateSwooleTimers;
use Hypervel\Cache\Redis\Console\BenchmarkCommand;
use Hypervel\Cache\Redis\Console\DoctorCommand;
use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Core\Events\AfterWorkerStart;
use Hypervel\Core\Events\BeforeServerStart;
use Hypervel\Core\Events\OnWorkerExit;
use Hypervel\Support\ServiceProvider;
use Throwable;

class CacheServiceProvider extends ServiceProvider
{
    /**
     * Register the service provider.
     */
    public function register(): void
    {
        $this->app->singleton('cache', fn ($app) => new CacheManager($app));

        $this->app->singleton('cache.store', fn ($app) => $app->make('cache')->driver());

        $this->app->singleton(RateLimiter::class, fn ($app) => new RateLimiter(
            $app->make('cache')->driver(
                $app->make('config')->get('cache.limiter')
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

        $events->listen(BeforeServerStart::class, function (BeforeServerStart $event): void {
            $this->app->make(CreateSwooleTable::class)->handle($event);
        });

        $events->listen(AfterWorkerStart::class, function (AfterWorkerStart $event): void {
            $this->app->make(CreateSwooleTimers::class)->handle($event);
        });

        $events->listen(OnWorkerExit::class, function (OnWorkerExit $event): void {
            if ($event->workerId !== 0 || $event->server->taskworker) {
                return;
            }

            try {
                $this->app->make(CreateSwooleTimers::class)->stop();
            } catch (Throwable $exception) {
                try {
                    $this->app->make(ExceptionHandler::class)->report($exception);
                } catch (Throwable $reportingFailure) {
                    try {
                        file_put_contents(
                            'php://stderr',
                            (string) $exception . PHP_EOL . (string) $reportingFailure . PHP_EOL,
                        );
                    } catch (Throwable) {
                    }
                }
            }
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
