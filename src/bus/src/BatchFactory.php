<?php

declare(strict_types=1);

namespace Hypervel\Bus;

use Carbon\CarbonImmutable;
use Hypervel\Contracts\Queue\Factory as QueueFactory;

class BatchFactory
{
    /**
     * Create a new batch factory instance.
     */
    public function __construct(
        protected QueueFactory $queue
    ) {
    }

    /**
     * Create a new batch instance.
     *
     * @return Batch
     */
    public function make(
        BatchRepository $repository,
        string $id,
        string $name,
        int $totalJobs,
        int $pendingJobs,
        int $failedJobs,
        array $failedJobIds,
        array $options,
        CarbonImmutable $createdAt,
        ?CarbonImmutable $cancelledAt,
        ?CarbonImmutable $finishedAt
    ) {
        return new Batch($this->queue, $repository, $id, $name, $totalJobs, $pendingJobs, $failedJobs, $failedJobIds, $options, $createdAt, $cancelledAt, $finishedAt);
    }
}
