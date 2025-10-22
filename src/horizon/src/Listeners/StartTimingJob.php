<?php

declare(strict_types=1);

namespace Hypervel\Horizon\Listeners;

use Hypervel\Horizon\Events\JobReserved;
use Hypervel\Horizon\Stopwatch;

class StartTimingJob
{
    /**
     * Create a new listener instance.
     *
     * @param Stopwatch $watch the stopwatch instance
     */
    public function __construct(
        public Stopwatch $watch
    ) {
    }

    /**
     * Handle the event.
     */
    public function handle(JobReserved $event): void
    {
        $this->watch->start($event->payload->id());
    }
}
