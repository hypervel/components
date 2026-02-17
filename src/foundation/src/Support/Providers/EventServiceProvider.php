<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Support\Providers;

use Hypervel\Contracts\Event\Dispatcher;
use Hypervel\Event\Contracts\ListenerProvider;
use Hypervel\Support\ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event listener mappings for the application.
     */
    protected array $listen = [];

    /**
     * The subscribers to register.
     */
    protected array $subscribe = [];

    public function register(): void
    {
        $provider = $this->app->make(ListenerProvider::class);
        foreach ($this->listen as $event => $listeners) {
            foreach ($listeners as $listener) {
                $instance = $this->app->make($listener);
                $provider->on($event, [$instance, 'handle']);
            }
        }

        $dispatcher = $this->app->make(Dispatcher::class);
        foreach ($this->subscribe as $subscriber) {
            $dispatcher->subscribe($subscriber);
        }
    }
}
