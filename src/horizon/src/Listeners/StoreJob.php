<?php

declare(strict_types=1);

namespace Hypervel\Horizon\Listeners;

use Hypervel\Horizon\Contracts\JobRepository;
use Hypervel\Horizon\Events\JobPushed;

class StoreJob
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
    public function handle(JobPushed $event): void
    {
        $this->jobs->pushed(
            $event->connectionName,
            $event->queue,
            $event->payload
        );
    }
}
