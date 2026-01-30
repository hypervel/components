<?php

declare(strict_types=1);

namespace Hypervel\Bus;

use Carbon\CarbonInterface;
use Closure;
use Hypervel\Context\ApplicationContext;
use Hypervel\Contracts\Bus\BatchRepository;
use Hypervel\Contracts\Debug\ExceptionHandler as ExceptionHandlerContract;
use Hypervel\Contracts\Queue\Factory as QueueFactory;
use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Queue\CallQueuedClosure;
use Hypervel\Support\Arr;
use Hypervel\Support\Collection;
use Hypervel\Support\Enumerable;
use JsonSerializable;
use Throwable;

use function Hyperf\Support\with;

class Batch implements Arrayable, JsonSerializable
{
    /**
     * Create a new batch instance.
     *
     * @param QueueFactory $queue the queue factory implementation
     * @param BatchRepository $repository the repository implementation
     * @param string $id the batch ID
     * @param string $name the batch name
     * @param int $totalJobs the total number of jobs that belong to the batch
     * @param int $pendingJobs the total number of jobs that are still pending
     * @param int $failedJobs the IDs of the jobs that have failed
     * @param array $failedJobIds the IDs of the jobs that have failed
     * @param array $options the batch options
     * @param \Carbon\CarbonInterface $createdAt the date indicating when the batch was created
     * @param null|\Carbon\CarbonInterface $cancelledAt the date indicating when the batch was cancelled
     * @param null|\Carbon\CarbonInterface $finishedAt the date indicating when the batch was finished
     */
    public function __construct(
        protected QueueFactory $queue,
        protected BatchRepository $repository,
        public string $id,
        public string $name,
        public int $totalJobs,
        public int $pendingJobs,
        public int $failedJobs,
        public array $failedJobIds,
        public array $options,
        public CarbonInterface $createdAt,
        public ?CarbonInterface $cancelledAt = null,
        public ?CarbonInterface $finishedAt = null
    ) {
    }

    /**
     * Get a fresh instance of the batch represented by this ID.
     */
    public function fresh(): ?Batch
    {
        return $this->repository->find($this->id);
    }

    /**
     * Add additional jobs to the batch.
     *
     * @param array|Enumerable|object $jobs
     */
    public function add(array|object $jobs): ?Batch
    {
        $count = 0;

        $jobs = Collection::wrap($jobs)->map(function ($job) use (&$count) {
            $job = $job instanceof Closure ? CallQueuedClosure::create($job) : $job;

            if (is_array($job)) {
                $count += count($job);

                return with($this->prepareBatchedChain($job), function ($chain) {
                    return $chain->first()
                        ->allOnQueue($this->options['queue'] ?? null)
                        ->allOnConnection($this->options['connection'] ?? null)
                        ->chain($chain->slice(1)->values()->all());
                });
            } else {
                $job->withBatchId($this->id);

                ++$count;
            }

            return $job;
        });

        $this->repository->transaction(function () use ($jobs, $count) {
            $this->repository->incrementTotalJobs($this->id, $count);

            $this->queue->connection($this->options['connection'] ?? null)->bulk(
                $jobs->all(),
                $data = '',
                $this->options['queue'] ?? null
            );
        });

        return $this->fresh();
    }

    /**
     * Prepare a chain that exists within the jobs being added.
     */
    protected function prepareBatchedChain(array $chain): Collection
    {
        return Collection::make($chain)->map(function ($job) {
            $job = $job instanceof Closure ? CallQueuedClosure::create($job) : $job;

            return $job->withBatchId($this->id);
        });
    }

    /**
     * Get the total number of jobs that have been processed by the batch thus far.
     */
    public function processedJobs(): int
    {
        return $this->totalJobs - $this->pendingJobs;
    }

    /**
     * Get the percentage of jobs that have been processed (between 0-100).
     */
    public function progress(): int
    {
        return $this->totalJobs > 0 ? (int) round(($this->processedJobs() / $this->totalJobs) * 100) : 0;
    }

    /**
     * Record that a job within the batch finished successfully, executing any callbacks if necessary.
     */
    public function recordSuccessfulJob(string $jobId): void
    {
        $counts = $this->decrementPendingJobs($jobId);

        if ($this->hasProgressCallbacks()) {
            $batch = $this->fresh();

            Collection::make($this->options['progress'])->each(function ($handler) use ($batch) {
                $this->invokeHandlerCallback($handler, $batch);
            });
        }

        if ($counts->pendingJobs === 0) {
            $this->repository->markAsFinished($this->id);
        }

        if ($counts->pendingJobs === 0 && $this->hasThenCallbacks()) {
            $batch = $this->fresh();

            Collection::make($this->options['then'])->each(function ($handler) use ($batch) {
                $this->invokeHandlerCallback($handler, $batch);
            });
        }

        if ($counts->allJobsHaveRanExactlyOnce() && $this->hasFinallyCallbacks()) {
            $batch = $this->fresh();

            Collection::make($this->options['finally'])->each(function ($handler) use ($batch) {
                $this->invokeHandlerCallback($handler, $batch);
            });
        }
    }

    /**
     * Decrement the pending jobs for the batch.
     */
    public function decrementPendingJobs(string $jobId): UpdatedBatchJobCounts
    {
        return $this->repository->decrementPendingJobs($this->id, $jobId);
    }

    /**
     * Determine if the batch has finished executing.
     */
    public function finished(): bool
    {
        return ! is_null($this->finishedAt);
    }

    /**
     * Determine if the batch has "progress" callbacks.
     */
    public function hasProgressCallbacks(): bool
    {
        return isset($this->options['progress']) && ! empty($this->options['progress']);
    }

    /**
     * Determine if the batch has "success" callbacks.
     */
    public function hasThenCallbacks(): bool
    {
        return isset($this->options['then']) && ! empty($this->options['then']);
    }

    /**
     * Determine if the batch allows jobs to fail without cancelling the batch.
     */
    public function allowsFailures(): bool
    {
        return Arr::get($this->options, 'allowFailures', false) === true;
    }

    /**
     * Determine if the batch has job failures.
     */
    public function hasFailures(): bool
    {
        return $this->failedJobs > 0;
    }

    /**
     * Record that a job within the batch failed to finish successfully, executing any callbacks if necessary.
     */
    public function recordFailedJob(string $jobId, Throwable $e): void
    {
        $counts = $this->incrementFailedJobs($jobId);

        if ($counts->failedJobs === 1 && ! $this->allowsFailures()) {
            $this->cancel();
        }

        if ($this->hasProgressCallbacks() && $this->allowsFailures()) {
            $batch = $this->fresh();

            Collection::make($this->options['progress'])->each(function ($handler) use ($batch, $e) {
                $this->invokeHandlerCallback($handler, $batch, $e);
            });
        }

        if ($counts->failedJobs === 1 && $this->hasCatchCallbacks()) {
            $batch = $this->fresh();

            Collection::make($this->options['catch'])->each(function ($handler) use ($batch, $e) {
                $this->invokeHandlerCallback($handler, $batch, $e);
            });
        }

        if ($counts->allJobsHaveRanExactlyOnce() && $this->hasFinallyCallbacks()) {
            $batch = $this->fresh();

            Collection::make($this->options['finally'])->each(function ($handler) use ($batch, $e) {
                $this->invokeHandlerCallback($handler, $batch, $e);
            });
        }
    }

    /**
     * Increment the failed jobs for the batch.
     */
    public function incrementFailedJobs(string $jobId): UpdatedBatchJobCounts
    {
        return $this->repository->incrementFailedJobs($this->id, $jobId);
    }

    /**
     * Determine if the batch has "catch" callbacks.
     */
    public function hasCatchCallbacks(): bool
    {
        return isset($this->options['catch']) && ! empty($this->options['catch']);
    }

    /**
     * Determine if the batch has "finally" callbacks.
     */
    public function hasFinallyCallbacks(): bool
    {
        return isset($this->options['finally']) && ! empty($this->options['finally']);
    }

    /**
     * Cancel the batch.
     */
    public function cancel(): void
    {
        $this->repository->cancel($this->id);
    }

    /**
     * Determine if the batch has been cancelled.
     */
    public function canceled(): bool
    {
        return $this->cancelled();
    }

    /**
     * Determine if the batch has been cancelled.
     */
    public function cancelled(): bool
    {
        return ! is_null($this->cancelledAt);
    }

    /**
     * Delete the batch from storage.
     */
    public function delete(): void
    {
        $this->repository->delete($this->id);
    }

    /**
     * Invoke a batch callback handler.
     */
    protected function invokeHandlerCallback(callable $handler, Batch $batch, ?Throwable $e = null): void
    {
        try {
            $handler($batch, $e);
        } catch (Throwable $e) {
            $container = ApplicationContext::getContainer();
            if (! $container->has(ExceptionHandlerContract::class)) {
                return;
            }
            $container->get(ExceptionHandlerContract::class)->report($e);
        }
    }

    /**
     * Convert the batch to an array.
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'totalJobs' => $this->totalJobs,
            'pendingJobs' => $this->pendingJobs,
            'processedJobs' => $this->processedJobs(),
            'progress' => $this->progress(),
            'failedJobs' => $this->failedJobs,
            'options' => $this->options,
            'createdAt' => $this->createdAt,
            'cancelledAt' => $this->cancelledAt,
            'finishedAt' => $this->finishedAt,
        ];
    }

    /**
     * Get the JSON serializable representation of the object.
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * Dynamically access the batch's "options" via properties.
     */
    public function __get(string $key)
    {
        return $this->options[$key] ?? null;
    }
}
