<?php

declare(strict_types=1);

namespace Hypervel\ServerProcess;

use Hypervel\Core\Events\BeforeMainServerStart;
use Hypervel\ServerProcess\Events\AfterProcessHandle;
use Hypervel\ServerProcess\Events\BeforeProcessHandle;
use Hypervel\ServerProcess\Listeners\BootProcessListener;
use Hypervel\ServerProcess\Listeners\LogAfterProcessStoppedListener;
use Hypervel\ServerProcess\Listeners\LogBeforeProcessStartListener;
use Hypervel\Support\ServiceProvider;

class ServerProcessServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the service provider.
     */
    public function boot(): void
    {
        $events = $this->app->make('events');

        $events->listen(BeforeMainServerStart::class, function (BeforeMainServerStart $event) {
            $this->app->make(BootProcessListener::class)->handle($event);
        });

        // Observers receive the event name and payload, not the event object.
        $events->observe(AfterProcessHandle::class, function (string $event, array $payload): void {
            $this->app->make(LogAfterProcessStoppedListener::class)->handle($payload[0]);
        });

        $events->observe(BeforeProcessHandle::class, function (string $event, array $payload): void {
            $this->app->make(LogBeforeProcessStartListener::class)->handle($payload[0]);
        });
    }
}
