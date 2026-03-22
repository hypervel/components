<?php

declare(strict_types=1);

namespace Hypervel\Bus;

use Carbon\CarbonImmutable;
use Closure;
use Hypervel\Bus\Events\BatchCanceled;
use Hypervel\Bus\Events\BatchFinished;
use Hypervel\Container\Container;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Queue\Factory as QueueFactory;
use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Queue\CallQueuedClosure;
use Hypervel\Support\Arr;
use Hypervel\Support\Collection;
use Hypervel\Support\Enumerable;
use JsonSerializable;
use Throwable;

class Batch implements Arrayable, JsonSerializable
{
    /**
     * Create a new batch instance.
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
        public CarbonImmutable $createdAt,
        public ?CarbonImmutable $cancelledAt = null,
        public ?CarbonImmutable $finishedAt = null,
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

                $chain = $this->prepareBatchedChain($job);

                return $chain->first()
                    ->allOnQueue($this->options['queue'] ?? null)
                    ->allOnConnection($this->options['connection'] ?? null)
                    ->chain($chain->slice(1)->values()->all());
            }
            $job->withBatchId($this->id);

            ++$count;

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
        return (new Collection($chain))->map(function ($job) {
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
     *
     * @return int<0, 100>
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
            $this->invokeCallbacks('progress');
        }

        if ($counts->pendingJobs === 0) {
            $this->repository->markAsFinished($this->id);

            $container = Container::getInstance();

            if ($container->bound(Dispatcher::class)) {
                $container->make(Dispatcher::class)->dispatch(new BatchFinished($this));
            }
        }

        if ($counts->pendingJobs === 0 && $this->hasThenCallbacks()) {
            $this->invokeCallbacks('then');
        }

        if ($counts->allJobsHaveRanExactlyOnce() && $this->hasFinallyCallbacks()) {
            $this->invokeCallbacks('finally');
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
     * Invoke the callbacks of the given type.
     */
    protected function invokeCallbacks(string $type, ?Throwable $e = null): void
    {
        $batch = $this->fresh();

        foreach ($this->options[$type] ?? [] as $handler) {
            $this->invokeHandlerCallback($handler, $batch, $e);
        }
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

        if ($this->allowsFailures()) {
            if ($this->hasProgressCallbacks()) {
                $this->invokeCallbacks('progress', $e);
            }

            if ($this->hasFailureCallbacks()) {
                $this->invokeCallbacks('failure', $e);
            }
        }

        if ($counts->failedJobs === 1 && $this->hasCatchCallbacks()) {
            $this->invokeCallbacks('catch', $e);
        }

        if ($counts->allJobsHaveRanExactlyOnce() && $this->hasFinallyCallbacks()) {
            $this->invokeCallbacks('finally');
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
     * Determine if the batch has "failure" callbacks.
     */
    public function hasFailureCallbacks(): bool
    {
        return isset($this->options['failure']) && ! empty($this->options['failure']);
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

        $container = Container::getInstance();

        if ($container->bound(Dispatcher::class)) {
            $container->make(Dispatcher::class)->dispatch(new BatchCanceled($this));
        }
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
            if (function_exists('report')) {
                report($e);
            }
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
    public function __get(string $key): mixed
    {
        return $this->options[$key] ?? null;
    }
}
