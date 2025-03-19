<?php

declare(strict_types=1);

namespace Hypervel\Queue\Events;

class JobPopping
{
    /**
     * Create a new event instance.
     */
    public function __construct(
        public string $connectionName
    ) {
    }
}
