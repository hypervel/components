<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Support\Providers;

use Hypervel\Foundation\Events\DiscoverEvents;
use Hypervel\Support\Facades\Event;
use Hypervel\Support\LazyCollection;
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
     * Indicates if events should be discovered.
     */
    protected static bool $shouldDiscoverEvents = true;

    /**
     * The configured event discovery paths.
     *
     * @var null|iterable<int, string>
     */
    protected static $eventDiscoveryPaths;

    /**
     * Register the application's event listeners.
     */
    public function register(): void
    {
        $this->booting(function () {
            $events = $this->getEvents();

            foreach ($events as $event => $listeners) {
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

    /**
     * Get the discovered events and listeners for the application.
     */
    public function getEvents(): array
    {
        if ($this->app->eventsAreCached()) {
            $cache = require $this->app->getCachedEventsPath();

            return $cache[get_class($this)] ?? [];
        }
        return array_merge_recursive(
            $this->discoveredEvents(),
            $this->listens()
        );
    }

    /**
     * Get the discovered events for the application.
     */
    protected function discoveredEvents(): array
    {
        return $this->shouldDiscoverEvents()
            ? $this->discoverEvents()
            : [];
    }

    /**
     * Determine if events and listeners should be automatically discovered.
     */
    public function shouldDiscoverEvents(): bool
    {
        return get_class($this) === __CLASS__ && static::$shouldDiscoverEvents === true;
    }

    /**
     * Discover the events and listeners for the application.
     */
    public function discoverEvents(): array
    {
        return (new LazyCollection($this->discoverEventsWithin()))
            ->flatMap(function ($directory) {
                return glob($directory, GLOB_ONLYDIR);
            })
            ->reject(function ($directory) {
                return ! is_dir($directory);
            })
            ->pipe(fn ($directories) => DiscoverEvents::within(
                $directories->all(),
                $this->eventDiscoveryBasePath(),
            ));
    }

    /**
     * Get the listener directories that should be used to discover events.
     *
     * @return iterable<int, string>
     */
    protected function discoverEventsWithin(): iterable
    {
        return static::$eventDiscoveryPaths ?: [
            $this->app->path('Listeners'),
        ];
    }

    /**
     * Add the given event discovery paths to the application's event discovery paths.
     *
     * @param iterable<int, string>|string $paths
     */
    public static function addEventDiscoveryPaths(iterable|string $paths): void
    {
        static::$eventDiscoveryPaths = (new LazyCollection(static::$eventDiscoveryPaths))
            ->merge(is_string($paths) ? [$paths] : $paths)
            ->unique()
            ->values();
    }

    /**
     * Set the globally configured event discovery paths.
     *
     * @param iterable<int, string> $paths
     */
    public static function setEventDiscoveryPaths(iterable $paths): void
    {
        static::$eventDiscoveryPaths = $paths;
    }

    /**
     * Get the base path to be used during event discovery.
     */
    protected function eventDiscoveryBasePath(): string
    {
        return base_path();
    }

    /**
     * Disable event discovery for the application.
     */
    public static function disableEventDiscovery(): void
    {
        static::$shouldDiscoverEvents = false;
    }

    /**
     * Flush the class's static state.
     */
    public static function flushState(): void
    {
        static::$shouldDiscoverEvents = true;
        static::$eventDiscoveryPaths = null;
    }
}
