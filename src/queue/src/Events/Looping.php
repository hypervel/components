<?php

declare(strict_types=1);

namespace Hypervel\Queue\Events;

class Looping
{
    /**
     * Create a new event instance.
     */
    public function __construct(
        public string $connectionName,
        public string $queue,
    ) {
    }
}
