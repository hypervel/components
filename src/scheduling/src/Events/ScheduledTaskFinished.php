<?php

declare(strict_types=1);

namespace Hypervel\Scheduling\Events;

use Hypervel\Scheduling\Event;

class ScheduledTaskFinished
{
    /**
     * Create a new event instance.
     */
    public function __construct(
        public Event $task,
        public float $runtime,
    ) {
    }
}
