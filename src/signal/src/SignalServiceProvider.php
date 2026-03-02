<?php

declare(strict_types=1);

namespace Hypervel\Signal;

use Hypervel\Framework\Events\BeforeWorkerStart;
use Hypervel\Framework\Events\OnWorkerExit;
use Hypervel\ServerProcess\Events\AfterProcessHandle;
use Hypervel\ServerProcess\Events\BeforeProcessHandle;
use Hypervel\Support\ServiceProvider;

class SignalServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the service provider.
     */
    public function boot(): void
    {
        $events = $this->app->make('events');

        $events->listen(BeforeWorkerStart::class, function (BeforeWorkerStart $event) {
            $this->app->make(SignalRegisterListener::class)->handle($event);
        });

        $events->listen(BeforeProcessHandle::class, function (BeforeProcessHandle $event) {
            $this->app->make(SignalRegisterListener::class)->handle($event);
        });

        $events->listen(OnWorkerExit::class, function (OnWorkerExit $event) {
            $this->app->make(SignalDeregisterListener::class)->handle($event);
        });

        $events->listen(AfterProcessHandle::class, function (AfterProcessHandle $event) {
            $this->app->make(SignalDeregisterListener::class)->handle($event);
        });

        $this->publishes([
            __DIR__ . '/../publish/signal.php' => BASE_PATH . '/config/autoload/signal.php',
        ]);
    }
}
