<?php

declare(strict_types=1);

namespace Hypervel\Broadcasting;

use UnitEnum;

class FakePendingBroadcast extends PendingBroadcast
{
    /**
     * Create a new fake pending broadcast instance.
     */
    public function __construct()
    {
    }

    /**
     * Broadcast the event using a specific broadcaster.
     */
    public function via(UnitEnum|string|null $connection = null): static
    {
        return $this;
    }

    /**
     * Broadcast the event to everyone except the current user.
     */
    public function toOthers(): static
    {
        return $this;
    }

    /**
     * Handle the object's destruction.
     */
    public function __destruct()
    {
    }
}
