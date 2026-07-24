<?php

declare(strict_types=1);

namespace Hypervel\Support\Testing\Fakes;

use Closure;
use Hypervel\Bus\Batch;
use Hypervel\Bus\PendingBatch;
use Hypervel\Support\Collection;
use Hypervel\Support\Traits\ReflectsClosures;

class PendingBatchFake extends PendingBatch
{
    use ReflectsClosures;

    /**
     * Create a new pending batch instance.
     *
     * @param BusFake $bus the fake bus instance
     */
    public function __construct(
        protected BusFake $bus,
        public Collection $jobs
    ) {
        $this->jobs = $jobs->filter()->values();
    }

    /**
     * Dispatch the batch.
     */
    public function dispatch(): Batch
    {
        return $this->bus->recordPendingBatch($this);
    }

    /**
     * Dispatch the batch after the response is sent to the browser.
     */
    public function dispatchAfterResponse(): Batch
    {
        return $this->bus->recordPendingBatch($this);
    }

    /**
     * Determine if the jobs in the batch match the given jobs.
     */
    public function hasJobs(array $expectedJobs): bool
    {
        if (count($this->jobs) !== count($expectedJobs)) {
            return false;
        }

        foreach ($expectedJobs as $index => $expectedJob) {
            if ($expectedJob instanceof Closure) {
                $expectedType = $this->firstClosureParameterType($expectedJob);

                if (! $this->jobs[$index] instanceof $expectedType) {
                    return false;
                }

                if (! $expectedJob($this->jobs[$index])) {
                    return false;
                }
            } elseif (is_string($expectedJob)) {
                if ($expectedJob !== get_class($this->jobs[$index])) {
                    return false;
                }
            } elseif (serialize($expectedJob) !== serialize($this->jobs[$index])) {
                return false;
            }
        }

        return true;
    }
}
