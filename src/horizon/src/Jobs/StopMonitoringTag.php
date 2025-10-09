<?php

declare(strict_types=1);

namespace Hypervel\Horizon\Jobs;

use Hypervel\Horizon\Contracts\JobRepository;
use Hypervel\Horizon\Contracts\TagRepository;

class StopMonitoringTag
{
    /**
     * Create a new job instance.
     *
     * @param string $tag The tag to stop monitoring
     */
    public function __construct(
        public string $tag
    ) {
    }

    /**
     * Execute the job.
     */
    public function handle(JobRepository $jobs, TagRepository $tags): void
    {
        $tags->stopMonitoring($this->tag);

        $monitored = $tags->paginate($this->tag);

        while (count($monitored) > 0) {
            $jobs->deleteMonitored($monitored);

            $offset = array_keys($monitored)[count($monitored) - 1] + 1;

            $monitored = $tags->paginate($this->tag, $offset);
        }

        $tags->forget($this->tag);
    }
}
