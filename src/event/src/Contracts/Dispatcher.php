<?php

declare(strict_types=1);

namespace Hypervel\Event\Contracts;

use Closure;
use Hypervel\Event\ListenerData;
use Hypervel\Event\QueuedClosure;
use Psr\EventDispatcher\EventDispatcherInterface;

interface Dispatcher extends EventDispatcherInterface
{
    /**
     * Fire an event and call the listeners.
     */
    public function dispatch(object|string $event, mixed $payload = [], bool $halt = false): mixed;

    /**
     * Register an event listener with the listener provider.
     */
    public function listen(
        array|Closure|QueuedClosure|string $events,
        array|Closure|int|QueuedClosure|string|null $listener = null,
        int $priority = ListenerData::DEFAULT_PRIORITY
    ): void;

    /**
     * Fire an event until the first non-null response is returned.
     */
    public function until(object|string $event, mixed $payload = []): mixed;

    /**
     * Get all of the listeners for a given event name.
     */
    public function getListeners(object|string $eventName): iterable;

    /**
     * Register an event and payload to be fired later.
     */
    public function push(string $event, mixed $payload = []): void;

    /**
     * Flush a set of pushed events.
     */
    public function flush(string $event): void;

    /**
     * Forget all of the pushed listeners.
     */
    public function forgetPushed(): void;

    /**
     * Remove a set of listeners from the dispatcher.
     */
    public function forget(string $event): void;

    /**
     * Determine if a given event has listeners.
     */
    public function hasListeners(string $eventName): bool;

    /**
     * Determine if the given event has any wildcard listeners.
     */
    public function hasWildcardListeners(string $eventName): bool;

    /**
     * Register an event subscriber with the dispatcher.
     */
    public function subscribe(object|string $subscriber): void;

    /**
     * Gets the raw, unprepared listeners.
     */
    public function getRawListeners(): array;
}
