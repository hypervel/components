<?php

declare(strict_types=1);

namespace Hypervel\Event;

use Closure;
use Hyperf\Support\Traits\ForwardsCalls;
use Hypervel\Contracts\Event\Dispatcher;

class NullDispatcher implements Dispatcher
{
    use ForwardsCalls;

    /**
     * Create a new event dispatcher instance that does not fire.
     */
    public function __construct(
        protected Dispatcher $dispatcher
    ) {
    }

    /**
     * Don't fire an event.
     */
    public function dispatch(object|string $event, mixed $payload = [], bool $halt = false): object|string
    {
        return '';
    }

    /**
     * Don't register an event and payload to be fired later.
     */
    public function push(string $event, mixed $payload = []): void
    {
        //
    }

    /**
     * Don't dispatch an event.
     */
    public function until(object|string $event, mixed $payload = []): object|string
    {
        return '';
    }

    /**
     * Register an event listener with the dispatcher.
     */
    public function listen(
        array|Closure|QueuedClosure|string $events,
        array|Closure|int|QueuedClosure|string|null $listener = null,
        int $priority = ListenerData::DEFAULT_PRIORITY
    ): void {
        $this->dispatcher->listen($events, $listener, $priority);
    }

    /**
     * Determine if a given event has listeners.
     */
    public function hasListeners(string $eventName): bool
    {
        return $this->dispatcher->hasListeners($eventName);
    }

    /**
     * Determine if the given event has any wildcard listeners.
     */
    public function hasWildcardListeners(string $eventName): bool
    {
        return $this->dispatcher->hasWildcardListeners($eventName);
    }

    /**
     * Register an event subscriber with the dispatcher.
     */
    public function subscribe(object|string $subscriber): void
    {
        $this->dispatcher->subscribe($subscriber);
    }

    /**
     * Flush a set of pushed events.
     */
    public function flush(string $event): void
    {
        $this->dispatcher->flush($event);
    }

    /**
     * Remove a set of listeners from the dispatcher.
     */
    public function forget(string $event): void
    {
        $this->dispatcher->forget($event);
    }

    /**
     * Forget all of the queued listeners.
     */
    public function forgetPushed(): void
    {
        $this->dispatcher->forgetPushed();
    }

    /**
     * Get all of the listeners for a given event name.
     */
    public function getListeners(object|string $eventName): iterable
    {
        return $this->dispatcher->getListeners($eventName);
    }

    /**
     * Gets the raw, unprepared listeners.
     */
    public function getRawListeners(): array
    {
        return $this->dispatcher->getRawListeners();
    }

    /**
     * Dynamically pass method calls to the underlying dispatcher.
     */
    public function __call(string $method, array $parameters): mixed
    {
        return $this->forwardCallTo($this->dispatcher, $method, $parameters);
    }
}
