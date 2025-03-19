<?php

declare(strict_types=1);

namespace Hypervel\Event\Contracts;

use Psr\EventDispatcher\ListenerProviderInterface as PsrListenerProviderInterface;

interface ListenerProviderContract extends PsrListenerProviderInterface
{
    /**
     * Get all of the listeners for a given event name.
     */
    public function getListenersForEvent(object|string $event): iterable;

    /**
     * Register an event listener with the listener provider.
     */
    public function on(string $event, array|callable|string $listener, int $priority): void;

    /**
     * Get all of the listeners for a given event name.
     */
    public function all(): array;

    /**
     * Remove a set of listeners from the dispatcher.
     */
    public function forget(string $event): void;

    /**
     * Determine if a given event has listeners.
     */
    public function has(string $event): bool;

    /**
     * Determine if the given event has any wildcard listeners.
     */
    public function hasWildcard(string $event): bool;
}
