<?php

declare(strict_types=1);

namespace Hypervel\Queue\Events;

class QueueFailedOver
{
    /**
     * Create a new event instance.
     */
    public function __construct(
        public ?string $connectionName,
        public object|string $command,
    ) {
    }
}
