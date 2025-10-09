<?php

declare(strict_types=1);

namespace Hypervel\Horizon\Listeners;

use Hypervel\Horizon\Contracts\JobRepository;
use Hypervel\Horizon\Events\JobsMigrated;

class MarkJobsAsMigrated
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
    public function handle(JobsMigrated $event): void
    {
        $this->jobs->migrated($event->connectionName, $event->queue, $event->payloads);
    }
}
