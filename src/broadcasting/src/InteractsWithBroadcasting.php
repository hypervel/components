<?php

declare(strict_types=1);

namespace Hypervel\Broadcasting;

use Hypervel\Support\Arr;
use UnitEnum;

use function Hypervel\Support\enum_value;

trait InteractsWithBroadcasting
{
    /**
     * The broadcaster connection to use to broadcast the event.
     */
    protected array $broadcastConnection = [null];

    /**
     * Broadcast the event using a specific broadcaster.
     */
    public function broadcastVia(UnitEnum|array|string|null $connection = null): static
    {
        $connection = is_null($connection) ? null : enum_value($connection);

        $this->broadcastConnection = is_null($connection)
            ? [null]
            : Arr::wrap($connection);

        return $this;
    }

    /**
     * Get the broadcaster connections the event should be broadcast on.
     */
    public function broadcastConnections(): array
    {
        return $this->broadcastConnection;
    }
}
