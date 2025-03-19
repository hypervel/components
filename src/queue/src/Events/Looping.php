<?php

declare(strict_types=1);

namespace Hypervel\Queue\Events;

class Looping
{
    public bool $shouldRun = true;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public string $connectionName,
        public string $queue
    ) {
    }

    public function shouldRun(): bool
    {
        return $this->shouldRun;
    }
}
