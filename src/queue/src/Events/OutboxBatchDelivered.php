<?php

declare(strict_types=1);

namespace Hypervel\Queue\Events;

class OutboxBatchDelivered
{
    /**
     * Create a new event instance.
     */
    public function __construct(
        public string $database,
        public string $connectionName,
        public int $accepted,
        public float $duration,
        public int $oldestEligibleAge,
    ) {
    }
}
