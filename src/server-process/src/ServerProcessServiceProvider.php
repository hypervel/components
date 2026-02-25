<?php

declare(strict_types=1);

namespace Hypervel\ServerProcess;

use Hypervel\Framework\Events\BeforeMainServerStart;
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
            $this->app->make(BootProcessListener::class)->process($event);
        });

        $events->listen(AfterProcessHandle::class, function (AfterProcessHandle $event) {
            $this->app->make(LogAfterProcessStoppedListener::class)->process($event);
        });

        $events->listen(BeforeProcessHandle::class, function (BeforeProcessHandle $event) {
            $this->app->make(LogBeforeProcessStartListener::class)->process($event);
        });
    }
}
