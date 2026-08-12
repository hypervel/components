<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent;

trait BroadcastsEventsAfterCommit
{
    use BroadcastsEvents;

    /**
     * Determine if the model event broadcast queued job should be dispatched after the open parent transactions are committed.
     */
    public function broadcastAfterCommit(): bool
    {
        return true;
    }
}
