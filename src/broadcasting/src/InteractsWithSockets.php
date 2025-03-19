<?php

declare(strict_types=1);

namespace Hypervel\Broadcasting;

use Hypervel\Support\Facades\Broadcast;

trait InteractsWithSockets
{
    /**
     * The socket ID for the user that raised the event.
     */
    public ?string $socket = null;

    /**
     * Exclude the current user from receiving the broadcast.
     */
    public function dontBroadcastToCurrentUser(): static
    {
        $this->socket = Broadcast::socket();

        return $this;
    }

    /**
     * Broadcast the event to everyone.
     */
    public function broadcastToEveryone(): static
    {
        $this->socket = null;

        return $this;
    }
}
