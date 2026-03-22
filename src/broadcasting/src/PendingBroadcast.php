<?php

declare(strict_types=1);

namespace Hypervel\Broadcasting;

use Hypervel\Contracts\Events\Dispatcher;
use UnitEnum;

use function Hypervel\Support\enum_value;

class PendingBroadcast
{
    /**
     * Create a new pending broadcast instance.
     */
    public function __construct(
        protected Dispatcher $eventDispatcher,
        protected mixed $event
    ) {
    }

    /**
     * Broadcast the event using a specific broadcaster.
     */
    public function via(UnitEnum|string|null $connection = null): static
    {
        if (method_exists($this->event, 'broadcastVia')) {
            $this->event->broadcastVia(enum_value($connection));
        }

        return $this;
    }

    /**
     * Broadcast the event to everyone except the current user.
     */
    public function toOthers(): static
    {
        if (method_exists($this->event, 'dontBroadcastToCurrentUser')) {
            $this->event->dontBroadcastToCurrentUser();
        }

        return $this;
    }

    /**
     * Handle the object's destruction.
     */
    public function __destruct()
    {
        $this->eventDispatcher->dispatch($this->event);
    }
}
