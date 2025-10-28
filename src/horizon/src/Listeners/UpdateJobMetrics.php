<?php

declare(strict_types=1);

namespace Hypervel\Horizon\Listeners;

use Hypervel\Horizon\Contracts\MetricsRepository;
use Hypervel\Horizon\Events\JobDeleted;
use Hypervel\Horizon\Stopwatch;

class UpdateJobMetrics
{
    /**
     * Create a new listener instance.
     *
     * @param MetricsRepository $metrics the metrics repository implementation
     * @param Stopwatch $watch the stopwatch instance
     */
    public function __construct(
        public MetricsRepository $metrics,
        public Stopwatch $watch
    ) {
    }

    /**
     * Stop gathering metrics for a job.
     */
    public function handle(JobDeleted $event): void
    {
        if ($event->job->hasFailed()) {
            return;
        }

        $time = $this->watch->check($id = $event->payload->id()) ?: 0;

        $this->metrics->incrementQueue(
            $event->job->getQueue(),
            $time
        );

        $this->metrics->incrementJob(
            $event->payload->displayName(),
            $time
        );

        $this->watch->forget($id);
    }
}
