<?php

declare(strict_types=1);

namespace Hypervel\Console\Events;

use Hypervel\Console\Scheduling\Event;

class ScheduledBackgroundTaskFinished
{
    /**
     * Create a new event instance.
     *
     * @param \Hypervel\Console\Scheduling\Event $task the scheduled event that ran
     */
    public function __construct(
        public Event $task,
    ) {
    }
}
