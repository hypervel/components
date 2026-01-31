<?php

declare(strict_types=1);

namespace Hypervel\Horizon\Listeners;

use Hypervel\Contracts\Event\Dispatcher;
use Hypervel\Horizon\Events\JobFailed;
use Hypervel\Queue\Events\JobFailed as LaravelJobFailed;
use Hypervel\Queue\Jobs\RedisJob;

class MarshalFailedEvent
{
    /**
     * Create a new listener instance.
     *
     * @param Dispatcher $events the event dispatcher implementation
     */
    public function __construct(
        public Dispatcher $events
    ) {
    }

    /**
     * Handle the event.
     */
    public function handle(LaravelJobFailed $event): void
    {
        if (! $event->job instanceof RedisJob) {
            return;
        }

        $this->events->dispatch((new JobFailed(
            $event->exception,
            $event->job,
            $event->job->getReservedJob()
        ))->connection($event->connectionName)->queue($event->job->getQueue()));
    }
}
