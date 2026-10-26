<?php

declare(strict_types=1);

namespace Hypervel\Queue\Events;

use Throwable;

class OutboxBatchDeliveryFailed
{
    /**
     * Create a new event instance.
     */
    public function __construct(
        public string $database,
        public string $connectionName,
        public int $accepted,
        public int $failed,
        public float $duration,
        public int $oldestEligibleAge,
        public Throwable $exception,
    ) {
    }
}
