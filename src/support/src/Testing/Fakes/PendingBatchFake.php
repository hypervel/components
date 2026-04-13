<?php

declare(strict_types=1);

namespace Hypervel\Support\Testing\Fakes;

use Hypervel\Bus\Batch;
use Hypervel\Bus\PendingBatch;
use Hypervel\Support\Collection;

class PendingBatchFake extends PendingBatch
{
    /**
     * Create a new pending batch instance.
     *
     * @param BusFake $bus the fake bus instance
     */
    public function __construct(
        protected BusFake $bus,
        public Collection $jobs
    ) {
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
}
