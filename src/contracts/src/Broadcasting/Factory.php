<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Broadcasting;

use Hypervel\Broadcasting\PendingBroadcast;

interface Factory
{
    /**
     * Get a broadcaster implementation by name.
     */
    public function connection(?string $name = null): Broadcaster;

    /**
     * Begin broadcasting an event.
     */
    public function event(mixed $event = null): PendingBroadcast;

    /**
     * Queue the given event for broadcast.
     */
    public function queue(mixed $event): void;
}
