<?php

declare(strict_types=1);

namespace Hypervel\Queue\Events;

use Throwable;

class QueueFailedOver
{
    /**
     * Create a new event instance.
     */
    public function __construct(
        public ?string $connectionName,
        public mixed $command,
        public Throwable $exception,
    ) {
    }
}
