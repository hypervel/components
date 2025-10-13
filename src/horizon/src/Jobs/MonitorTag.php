<?php

declare(strict_types=1);

namespace Hypervel\Horizon\Jobs;

use Hypervel\Horizon\Contracts\TagRepository;

class MonitorTag
{
    /**
     * Create a new job instance.
     *
     * @param string $tag The tag to monitor
     */
    public function __construct(
        public string $tag
    ) {
    }

    /**
     * Execute the job.
     */
    public function handle(TagRepository $tags): void
    {
        $tags->monitor($this->tag);
    }
}
