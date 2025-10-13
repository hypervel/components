<?php

declare(strict_types=1);

namespace Hypervel\Horizon\Listeners;

use Hypervel\Horizon\Stopwatch;
use Hypervel\Queue\Events\JobExceptionOccurred;
use Hypervel\Queue\Events\JobFailed;

class ForgetJobTimer
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
    public function handle(JobExceptionOccurred|JobFailed $event): void
    {
        $this->watch->forget($event->job->getJobId());
    }
}
