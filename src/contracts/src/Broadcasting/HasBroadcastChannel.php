<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Broadcasting;

interface HasBroadcastChannel
{
    /**
     * Get the broadcast channel route definition that is associated with the given entity.
     */
    public function broadcastChannelRoute(): string;

    /**
     * Get the broadcast channel name that is associated with the given entity.
     */
    public function broadcastChannel(): string;
}
