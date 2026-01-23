<?php

declare(strict_types=1);

namespace Hypervel\Event;

use Hypervel\Support\Collection;
use Hyperf\Stdlib\SplPriorityQueue;
use Hyperf\Stringable\Str;
use Hypervel\Event\Contracts\ListenerProvider as ListenerProviderContract;

class ListenerProvider implements ListenerProviderContract
{
    public array $listeners = [];

    public array $wildcards = [];

    public array $listenersCache = [];

    /**
     * Get all of the listeners for a given event name.
     */
    public function getListenersForEvent(object|string $event): iterable
    {
        $eventName = is_string($event) ? $event : get_class($event);

        $listeners = [];
        if (! is_null($cache = $this->listenersCache[$eventName] ?? null)) {
            $listeners = $cache;
        } else {
            $listeners = $this->getListenersUsingCondition(
                $this->listeners,
                fn ($_, $key) => is_string($event) ? $event === $key : $event instanceof $key
            );

            $wildcards = $this->getListenersUsingCondition(
                $this->wildcards,
                fn ($_, $key) => Str::is($key, $eventName)
            );

            $listeners = $listeners->merge($wildcards)->toArray();
            $this->listenersCache[$eventName] = $listeners;
        }

        $queue = new SplPriorityQueue();

        foreach ($listeners as $index => $listener) {
            $queue->insert($listener, $index * -1);
        }

        return $queue;
    }

    /**
     * Register an event listener with the listener provider.
     */
    public function on(
        string $event,
        array|callable|string $listener,
        int $priority = ListenerData::DEFAULT_PRIORITY
    ): void {
        $this->listenersCache = [];

        $listenerData = new ListenerData($event, $listener, $priority);
        if ($this->isWildcardEvent($event)) {
            $this->wildcards[$event][] = $listenerData;

            return;
        }

        $this->listeners[$event][] = $listenerData;
    }

    /**
     * Get all of the listeners for a given event name.
     */
    public function all(): array
    {
        return $this->listeners;
    }

    /**
     * Remove a set of listeners from the dispatcher.
     */
    public function forget(string $event): void
    {
        $this->listenersCache = [];

        if ($this->isWildcardEvent($event)) {
            unset($this->wildcards[$event]);

            return;
        }

        unset($this->listeners[$event]);
    }

    /**
     * Determine if a given event has listeners.
     */
    public function has(string $event): bool
    {
        return isset($this->listeners[$event])
            || isset($this->wildcards[$event])
            || $this->hasWildcard($event);
    }

    /**
     * Determine if the given event has any wildcard listeners.
     */
    public function hasWildcard(string $event): bool
    {
        foreach ($this->wildcards as $key => $_) {
            if (Str::is($key, $event)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get listeners using condition.
     */
    protected function getListenersUsingCondition(array $listeners, callable $filter): Collection
    {
        return collect($listeners)
            ->filter($filter)
            ->flatten(1)
            ->map(function ($listener, $index) {
                return [
                    'listener' => $listener->listener,
                    'priority' => $listener->priority,
                    'index' => $index,
                ];
            })
            ->sortBy([
                ['priority', 'desc'],
                ['index', 'asc'],
            ])
            ->pluck('listener');
    }

    /**
     * Determine if the event is a wildcard event.
     */
    protected function isWildcardEvent(string $event): bool
    {
        return str_contains($event, '*');
    }
}
