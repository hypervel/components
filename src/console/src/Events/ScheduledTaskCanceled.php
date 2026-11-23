<?php

declare(strict_types=1);

namespace Hypervel\Console\Events;

use Hypervel\Console\Scheduling\Event;
use Throwable;

/**
 * Dispatched when coroutine cancellation stops a scheduled task after its
 * starting notification, before or while its outcome is published.
 */
class ScheduledTaskCanceled
{
    /**
     * Create a new event instance.
     *
     * @param Throwable $cancellation the cancellation, or a failure carrying it, that stopped the task
     * @param null|float $runtime the task's runtime, or null when cancellation stopped it before it finished running
     * @param null|Throwable $exception the task's failure, when cancellation followed it
     */
    public function __construct(
        public Event $task,
        public Throwable $cancellation,
        public ?float $runtime = null,
        public ?Throwable $exception = null,
    ) {
    }
}
