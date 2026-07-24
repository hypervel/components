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
        $this->broadcastConnection = is_null($connection)
            ? [null]
            : array_map(
                fn ($value) => $value instanceof UnitEnum ? (string) enum_value($value) : $value,
                Arr::wrap($connection)
            );

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
