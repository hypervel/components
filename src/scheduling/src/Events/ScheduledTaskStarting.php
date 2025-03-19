<?php

declare(strict_types=1);

namespace Hypervel\Scheduling\Events;

use Hypervel\Scheduling\Event;

class ScheduledTaskStarting
{
    /**
     * Create a new event instance.
     */
    public function __construct(
        public Event $task,
    ) {
    }
}
