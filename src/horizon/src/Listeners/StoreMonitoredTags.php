<?php

declare(strict_types=1);

namespace Hypervel\Horizon\Listeners;

use Hypervel\Horizon\Contracts\TagRepository;
use Hypervel\Horizon\Events\JobPushed;

class StoreMonitoredTags
{
    /**
     * Create a new listener instance.
     *
     * @param TagRepository $tags the tag repository implementation
     */
    public function __construct(
        public TagRepository $tags
    ) {
    }

    /**
     * Handle the event.
     */
    public function handle(JobPushed $event): void
    {
        $monitoring = $this->tags->monitored($event->payload->tags());

        if (! empty($monitoring)) {
            $this->tags->add($event->payload->id(), $monitoring);
        }
    }
}
