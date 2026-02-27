<?php

declare(strict_types=1);

namespace Hypervel\Event;

use Hypervel\Event\Contracts\ListenerProvider as ListenerProviderContract;
use Hypervel\Support\Collection;
use Hypervel\Support\Str;

class ListenerProvider implements ListenerProviderContract
{
    public array $listeners = [];

    public array $wildcards = [];

    public array $listenersCache = [];

    /**
     * Get all of the listeners for a given event name.
     *
     * @return iterable<array{listener: mixed, isWildcard: bool}>
     */
    public function getListenersForEvent(object|string $event): iterable
    {
        $eventName = is_string($event) ? $event : get_class($event);

        if (isset($this->listenersCache[$eventName])) {
            return $this->listenersCache[$eventName];
        }

        $listeners = $this->getListenersUsingCondition(
            $this->listeners,
            fn ($_, $key) => is_string($event) ? $event === $key : $event instanceof $key,
            isWildcard: false
        );

        $wildcards = $this->getListenersUsingCondition(
            $this->wildcards,
            fn ($_, $key) => Str::is($key, $eventName),
            isWildcard: true
        );

        $result = $listeners->merge($wildcards)->values()->all();
        $this->listenersCache[$eventName] = $result;

        return $result;
    }

    /**
     * Register an event listener with the listener provider.
     */
    public function on(string $event, array|callable|string $listener): void
    {
        $this->listenersCache = [];

        if ($this->isWildcardEvent($event)) {
            $this->wildcards[$event][] = $listener;

            return;
        }

        $this->listeners[$event][] = $listener;
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
     *
     * @return Collection<int, array{listener: mixed, isWildcard: bool}>
     */
    protected function getListenersUsingCondition(array $listeners, callable $filter, bool $isWildcard = false): Collection
    {
        return collect($listeners)
            ->filter($filter)
            ->flatten(1)
            ->map(fn ($listener) => ['listener' => $listener, 'isWildcard' => $isWildcard]);
    }

    /**
     * Determine if the event is a wildcard event.
     */
    protected function isWildcardEvent(string $event): bool
    {
        return str_contains($event, '*');
    }
}
