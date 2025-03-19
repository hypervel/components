<?php

declare(strict_types=1);

namespace Hypervel\Scheduling\Events;

use Hypervel\Scheduling\Event;
use Throwable;

class ScheduledTaskFailed
{
    /**
     * Create a new event instance.
     */
    public function __construct(
        public Event $task,
        public Throwable $exception,
    ) {
    }
}
