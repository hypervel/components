<?php

declare(strict_types=1);

namespace Hypervel\WebSocketServer;

use Hypervel\Framework\Events\AfterWorkerStart;
use Hypervel\Framework\Events\OnPipeMessage;
use Hypervel\Support\ServiceProvider;
use Hypervel\WebSocketServer\Listeners\InitSenderListener;
use Hypervel\WebSocketServer\Listeners\OnPipeMessageListener;

class WebSocketServerServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the service provider.
     */
    public function boot(): void
    {
        $events = $this->app->make('events');

        $events->listen(AfterWorkerStart::class, function (AfterWorkerStart $event) {
            $this->app->make(InitSenderListener::class)->handle($event);
        });

        $events->listen(OnPipeMessage::class, function (OnPipeMessage $event) {
            $this->app->make(OnPipeMessageListener::class)->handle($event);
        });
    }
}
