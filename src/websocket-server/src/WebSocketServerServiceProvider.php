<?php

declare(strict_types=1);

namespace Hypervel\WebSocketServer;

use Hypervel\Core\Events\OnPipeMessage;
use Hypervel\Support\ServiceProvider;
use Hypervel\WebSocketServer\Listeners\OnPipeMessageListener;

class WebSocketServerServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the service provider.
     */
    public function boot(): void
    {
        $events = $this->app->make('events');

        $events->listen(OnPipeMessage::class, function (OnPipeMessage $event) {
            if ($event->data instanceof SenderPipeMessage) {
                $this->app->make(OnPipeMessageListener::class)->handle($event->data);
            }
        });
    }
}
