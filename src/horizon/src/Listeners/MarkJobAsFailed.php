<?php

declare(strict_types=1);

namespace Hypervel\Horizon\Listeners;

use Hypervel\Horizon\Contracts\JobRepository;
use Hypervel\Horizon\Events\JobFailed;

class MarkJobAsFailed
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
    public function handle(JobFailed $event): void
    {
        $this->jobs->failed(
            $event->exception,
            $event->connectionName,
            $event->queue,
            $event->payload
        );
    }
}
