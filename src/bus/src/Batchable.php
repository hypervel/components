<?php

declare(strict_types=1);

namespace Hypervel\Bus;

use Carbon\CarbonImmutable;
use Hypervel\Container\Container;
use Hypervel\Support\Str;
use Hypervel\Support\Testing\Fakes\BatchFake;

trait Batchable
{
    /**
     * The batch ID (if applicable).
     */
    public ?string $batchId = null;

    /**
     * The fake batch, if applicable.
     */
    private ?BatchFake $fakeBatch = null;

    /**
     * Get the batch instance for the job, if applicable.
     */
    public function batch(): ?Batch
    {
        if ($this->fakeBatch) {
            return $this->fakeBatch;
        }

        if ($this->batchId) {
            return Container::getInstance()
                ->make(BatchRepository::class)
                ->find($this->batchId);
        }

        return null;
    }

    /**
     * Determine if the batch is still active and processing.
     */
    public function batching(): bool
    {
        $batch = $this->batch();

        return $batch && ! $batch->cancelled();
    }

    /**
     * Set the batch ID on the job.
     */
    public function withBatchId(string $batchId): static
    {
        $this->batchId = $batchId;

        return $this;
    }

    /**
     * Indicate that the job should use a fake batch.
     */
    public function withFakeBatch(
        string $id = '',
        string $name = '',
        int $totalJobs = 0,
        int $pendingJobs = 0,
        int $failedJobs = 0,
        array $failedJobIds = [],
        array $options = [],
        ?CarbonImmutable $createdAt = null,
        ?CarbonImmutable $cancelledAt = null,
        ?CarbonImmutable $finishedAt = null
    ): array {
        $this->fakeBatch = new BatchFake(
            empty($id) ? (string) Str::uuid() : $id,
            $name,
            $totalJobs,
            $pendingJobs,
            $failedJobs,
            $failedJobIds,
            $options,
            $createdAt ?? CarbonImmutable::now(),
            $cancelledAt,
            $finishedAt,
        );

        return [$this, $this->fakeBatch];
    }
}
