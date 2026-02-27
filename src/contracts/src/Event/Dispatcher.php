<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Event;

use Closure;
use Hypervel\Events\QueuedClosure;

interface Dispatcher
{
    /**
     * Register an event listener with the dispatcher.
     */
    public function listen(
        array|Closure|QueuedClosure|string $events,
        array|Closure|QueuedClosure|string|null $listener = null
    ): void;

    /**
     * Determine if a given event has listeners.
     */
    public function hasListeners(string $eventName): bool;

    /**
     * Register an event subscriber with the dispatcher.
     */
    public function subscribe(object|string $subscriber): void;

    /**
     * Dispatch an event until the first non-null response is returned.
     */
    public function until(object|string $event, mixed $payload = []): mixed;

    /**
     * Dispatch an event and call the listeners.
     */
    public function dispatch(object|string $event, mixed $payload = [], bool $halt = false): mixed;

    /**
     * Register an event and payload to be fired later.
     */
    public function push(string $event, mixed $payload = []): void;

    /**
     * Flush a set of pushed events.
     */
    public function flush(string $event): void;

    /**
     * Remove a set of listeners from the dispatcher.
     */
    public function forget(string $event): void;

    /**
     * Forget all of the queued listeners.
     */
    public function forgetPushed(): void;
}
