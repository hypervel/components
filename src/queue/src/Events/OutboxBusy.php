<?php

declare(strict_types=1);

namespace Hypervel\Queue\Events;

class OutboxBusy
{
    /**
     * Create a new event instance.
     */
    public function __construct(
        public string $database,
        public int $size,
        public int $eligible,
        public ?int $oldestEligibleAge,
    ) {
    }
}
