<?php

declare(strict_types=1);

namespace Hypervel\Horizon\Listeners;

use Hypervel\Horizon\Contracts\TagRepository;
use Hypervel\Horizon\Events\JobFailed;

class StoreTagsForFailedJob
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
    public function handle(JobFailed $event): void
    {
        $tags = collect($event->payload->tags())->map(function ($tag) {
            return 'failed:' . $tag;
        })->all();

        $this->tags->addTemporary(
            config('horizon.trim.failed', 2880),
            $event->payload->id(),
            $tags
        );
    }
}
