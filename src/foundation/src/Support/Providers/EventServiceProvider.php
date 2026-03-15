<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Support\Providers;

use Hypervel\Support\Facades\Event;
use Hypervel\Support\ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event handler mappings for the application.
     *
     * @var array<string, array<int, string>>
     */
    protected array $listen = [];

    /**
     * The subscribers to register.
     */
    protected array $subscribe = [];

    /**
     * The model observers to register.
     *
     * @var array<string, array<int, object|string>|object|string>
     */
    protected array $observers = [];

    /**
     * Register the application's event listeners.
     */
    public function register(): void
    {
        $this->booting(function () {
            foreach ($this->listens() as $event => $listeners) {
                foreach (array_unique($listeners, SORT_REGULAR) as $listener) {
                    Event::listen($event, $listener);
                }
            }

            foreach ($this->subscribe as $subscriber) {
                Event::subscribe($subscriber);
            }

            foreach ($this->observers as $model => $observers) {
                $model::observe($observers);
            }
        });
    }

    /**
     * Boot any application services.
     */
    public function boot(): void
    {
    }

    /**
     * Get the events and handlers.
     */
    public function listens(): array
    {
        return $this->listen;
    }
}
