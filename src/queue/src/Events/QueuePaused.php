<?php

declare(strict_types=1);

namespace Hypervel\Queue\Events;

use DateInterval;
use DateTimeInterface;

class QueuePaused
{
    /**
     * Create a new event instance.
     */
    public function __construct(
        public string $connection,
        public string $queue,
        public DateInterval|DateTimeInterface|int|null $ttl = null,
    ) {
    }
}
