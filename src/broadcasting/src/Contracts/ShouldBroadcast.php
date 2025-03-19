<?php

declare(strict_types=1);

namespace Hypervel\Broadcasting\Contracts;

use Hypervel\Broadcasting\Channel;

interface ShouldBroadcast
{
    /**
     * Get the channels the event should broadcast on.
     *
     * @return Channel[]|string[]
     */
    public function broadcastOn(): array;
}
