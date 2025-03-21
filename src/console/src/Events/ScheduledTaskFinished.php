<?php

declare(strict_types=1);

namespace Hypervel\Console\Events;

use Hypervel\Console\Scheduling\Event;

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
