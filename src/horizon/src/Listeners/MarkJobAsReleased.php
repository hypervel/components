<?php

declare(strict_types=1);

namespace Hypervel\Horizon\Listeners;

use Hypervel\Horizon\Contracts\JobRepository;
use Hypervel\Horizon\Events\JobReleased;

class MarkJobAsReleased
{
    /**
     * Create a new listener instance.
     *
     * @param JobRepository $jobs the job repository implementation
     */
    public function __construct(
        public JobRepository $jobs
    ) {
    }

    /**
     * Handle the event.
     */
    public function handle(JobReleased $event): void
    {
        $this->jobs->released($event->connectionName, $event->queue, $event->payload);
    }
}
