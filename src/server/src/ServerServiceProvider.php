<?php

declare(strict_types=1);

namespace Hypervel\Server;

use Hypervel\Framework\Events\AfterWorkerStart;
use Hypervel\Framework\Events\OnManagerStart;
use Hypervel\Framework\Events\OnStart;
use Hypervel\Server\Command\StartServer;
use Hypervel\Server\Listener\AfterWorkerStartListener;
use Hypervel\Server\Listener\InitProcessTitleListener;
use Hypervel\ServerProcess\Events\BeforeProcessHandle;
use Hypervel\Support\ServiceProvider;
use Swoole\Server as SwooleServer;

class ServerServiceProvider extends ServiceProvider
{
    /**
     * Register the service provider.
     */
    public function register(): void
    {
        $this->app->singleton(SwooleServer::class, fn ($app) => $app->make(SwooleServerFactory::class)($app));

        $this->commands([
            StartServer::class,
        ]);
    }

    /**
     * Bootstrap the service provider.
     */
    public function boot(): void
    {
        $events = $this->app->make('events');

        $events->listen(AfterWorkerStart::class, function (AfterWorkerStart $event) {
            $this->app->make(AfterWorkerStartListener::class)->process($event);
        });

        $events->listen(OnStart::class, function (OnStart $event) {
            $this->app->make(InitProcessTitleListener::class)->process($event);
        });

        $events->listen(OnManagerStart::class, function (OnManagerStart $event) {
            $this->app->make(InitProcessTitleListener::class)->process($event);
        });

        $events->listen(AfterWorkerStart::class, function (AfterWorkerStart $event) {
            $this->app->make(InitProcessTitleListener::class)->process($event);
        });

        $events->listen(BeforeProcessHandle::class, function (BeforeProcessHandle $event) {
            $this->app->make(InitProcessTitleListener::class)->process($event);
        });
    }
}
