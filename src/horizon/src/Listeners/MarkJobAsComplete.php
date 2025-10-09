<?php

declare(strict_types=1);

namespace Hypervel\Horizon\Listeners;

use Hypervel\Horizon\Contracts\JobRepository;
use Hypervel\Horizon\Contracts\TagRepository;
use Hypervel\Horizon\Events\JobDeleted;

class MarkJobAsComplete
{
    /**
     * Create a new listener instance.
     *
     * @param JobRepository $jobs the job repository implementation
     * @param TagRepository $tags the tag repository implementation
     */
    public function __construct(
        public JobRepository $jobs,
        public TagRepository $tags
    ) {
    }

    /**
     * Handle the event.
     */
    public function handle(JobDeleted $event): void
    {
        $this->jobs->completed($event->payload, $event->job->hasFailed(), $event->payload->isSilenced());

        if (! $event->job->hasFailed() && count($this->tags->monitored($event->payload->tags())) > 0) {
            $this->jobs->remember($event->connectionName, $event->queue, $event->payload);
        }
    }
}
