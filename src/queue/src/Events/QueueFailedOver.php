<?php

declare(strict_types=1);

namespace Hypervel\Queue\Events;

use Throwable;

class QueueFailedOver
{
    /**
     * Create a new event instance.
     *
     * @param null|string $connectionName the queue connection that failed
     * @param object|string $command the job instance
     */
    public function __construct(
        public ?string $connectionName,
        public object|string $command,
        public Throwable $exception,
    ) {
    }
}
