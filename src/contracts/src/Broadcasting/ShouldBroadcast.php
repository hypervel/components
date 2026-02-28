<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Broadcasting;

use Hypervel\Broadcasting\Channel;

interface ShouldBroadcast
{
    /**
     * Get the channels the event should broadcast on.
     *
     * @return Channel|Channel[]|string[]
     */
    public function broadcastOn(): array|Channel;
}
