<?php

declare(strict_types=1);

namespace Hypervel\Console\Events;

use Hypervel\Console\Scheduling\Event;
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
