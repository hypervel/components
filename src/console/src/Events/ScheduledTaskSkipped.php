<?php

declare(strict_types=1);

namespace Hypervel\Console\Events;

use Hypervel\Console\Scheduling\Event;
use Hypervel\Console\Scheduling\ScheduledTaskSkipReason;

class ScheduledTaskSkipped
{
    /**
     * Create a new event instance.
     *
     * @param Event $task the scheduled event being run
     * @param null|ScheduledTaskSkipReason $reason the reason why the task was skipped
     */
    public function __construct(
        public Event $task,
        public ?ScheduledTaskSkipReason $reason = null,
    ) {
    }
}
