<?php

declare(strict_types=1);

namespace Hypervel\Scheduling\Contracts;

use Hypervel\Scheduling\Event;

interface EventMutex
{
    /**
     * Attempt to obtain an event mutex for the given event.
     */
    public function create(Event $event): bool;

    /**
     * Determine if an event mutex exists for the given event.
     */
    public function exists(Event $event): bool;

    /**
     * Clear the event mutex for the given event.
     */
    public function forget(Event $event): void;
}
