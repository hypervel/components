<?php

declare(strict_types=1);

namespace Hypervel\RateLimiter;

use Hypervel\Contracts\Foundation\ReloadsConfiguration;
use Hypervel\Core\Events\AfterWorkerStart;
use Hypervel\Core\Events\BeforeServerStart;
use Hypervel\RateLimiter\Console\PruneCommand;
use Hypervel\RateLimiter\Console\RateLimiterTableCommand;
use Hypervel\RateLimiter\Listeners\InitializeSwooleTables;
use Hypervel\RateLimiter\Listeners\RegisterPruneTimer;
use Hypervel\Support\ServiceProvider;

class RateLimiterServiceProvider extends ServiceProvider implements ReloadsConfiguration
{
    /**
     * Register the service provider.
     */
    public function register(): void
    {
        $this->commands([
            PruneCommand::class,
            RateLimiterTableCommand::class,
        ]);
    }

    /**
     * Reload configuration-derived worker state.
     *
     * Boot-only. Request-time use clears shared rate limiter stores while
     * concurrent coroutines may still be using them.
     */
    public function reloadConfiguration(): void
    {
        if ($this->app->resolved(RateLimiter::class)) {
            $this->app->make(RateLimiter::class)->forgetInstances();
        }
    }

    /**
     * Bootstrap the service provider.
     */
    public function boot(): void
    {
        $events = $this->app->make('events');

        $events->listen(BeforeServerStart::class, function (BeforeServerStart $event): void {
            $this->app->make(InitializeSwooleTables::class)->handle($event);
        });

        $events->listen(AfterWorkerStart::class, function (AfterWorkerStart $event): void {
            $this->app->make(RegisterPruneTimer::class)->handle($event);
        });
    }
}
