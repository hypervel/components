<?php

declare(strict_types=1);

namespace Hypervel\Bus;

use Closure;
use Hypervel\Bus\Events\BatchCanceled;
use Hypervel\Bus\Events\BatchFinished;
use Hypervel\Bus\Events\BatchStarted;
use Hypervel\Container\Container;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Queue\Factory as QueueFactory;
use Hypervel\Contracts\Queue\Job;
use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Database\Connection;
use Hypervel\Database\ConnectionResolverInterface;
use Hypervel\Queue\CallQueuedClosure;
use Hypervel\Queue\EnqueuesBatchJobs;
use Hypervel\Queue\InteractsWithQueue;
use Hypervel\Support\Arr;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Collection;
use Hypervel\Support\Enumerable;
use Hypervel\Support\Str;
use JsonSerializable;
use LogicException;
use RuntimeException;
use Throwable;
use WeakMap;

class Batch implements Arrayable, JsonSerializable
{
    /**
     * The batch job IDs of commands being processed that cannot reach their queued job.
     *
     * @var null|WeakMap<object, string>
     */
    protected static ?WeakMap $commandJobIds = null;

    /** @var null|array{int, ?CarbonImmutable} SQL metadata captured before the first Redis progress read. */
    private ?array $progressAtLookup = null;

    public int $totalJobs {
        &get {
            if ($this->progressAtLookup !== null) {
                $this->loadJobCounts();
            }

            return $this->totalJobs;
        }
    }

    public int $pendingJobs {
        &get {
            if ($this->progressAtLookup !== null) {
                $this->loadJobCounts();
            }

            return $this->pendingJobs;
        }
    }

    public int $failedJobs {
        &get {
            if ($this->progressAtLookup !== null) {
                $this->loadJobCounts();
            }

            return $this->failedJobs;
        }
    }

    public ?CarbonImmutable $finishedAt {
        &get {
            if ($this->progressAtLookup !== null) {
                $this->loadJobCounts();
            }

            return $this->finishedAt;
        }
    }

    /**
     * The outstanding retry IDs, loaded when first read.
     */
    public array $failedJobIds {
        &get {
            if (! isset($this->failedJobIds)) {
                $this->failedJobIds = $this->repository->getFailedJobIds($this->id);
            }

            return $this->failedJobIds;
        }
    }

    /**
     * Create a new batch instance.
     */
    public function __construct(
        protected QueueFactory $queue,
        protected BatchRepository $repository,
        public string $id,
        public string $name,
        int $totalJobs,
        ?int $pendingJobs,
        ?int $failedJobs,
        ?array $failedJobIds,
        public array $options,
        public CarbonImmutable $createdAt,
        public ?CarbonImmutable $cancelledAt = null,
        ?CarbonImmutable $finishedAt = null,
    ) {
        if ($pendingJobs === null || $failedJobs === null) {
            $this->progressAtLookup = [$totalJobs, $finishedAt];
        } else {
            $this->totalJobs = $totalJobs;
            $this->pendingJobs = $pendingJobs;
            $this->failedJobs = $failedJobs;
            $this->finishedAt = $finishedAt;
        }

        if ($failedJobIds !== null) {
            $this->failedJobIds = $failedJobIds;
        }
    }

    /**
     * Load the Redis progress properties as one snapshot.
     */
    private function loadJobCounts(): void
    {
        if ($this->loadMissingJobCounts() === null) {
            throw new RuntimeException("Batch [{$this->id}] was deleted before its progress was loaded.");
        }
    }

    /**
     * Load a stable progress snapshot, returning null if its batch disappeared.
     *
     * @internal
     */
    public function loadMissingJobCounts(): ?static
    {
        if ($this->progressAtLookup === null) {
            return $this;
        }

        assert($this->repository instanceof RedisBatchRepository);
        $counts = $this->repository->loadJobCounts($this->id, ...$this->progressAtLookup);
        if ($counts === null) {
            return null;
        }

        $this->fillJobCounts($counts);

        return $this;
    }

    /**
     * Fill the snapshot while retaining any values already written by the caller.
     */
    private function fillJobCounts(array $counts): void
    {
        $this->progressAtLookup = null;
        // Casting reads initialized backing values without invoking the progress getters.
        $values = (array) $this + $counts;
        $this->totalJobs = $values['totalJobs'];
        $this->pendingJobs = $values['pendingJobs'];
        $this->failedJobs = $values['failedJobs'];
        $this->finishedAt = $values['finishedAt'];
        if ($counts['outstandingFailedJobs'] === 0 && ! array_key_exists('failedJobIds', $values)) {
            $this->failedJobIds = [];
        }
    }

    /**
     * Give event listeners the recorded transition even if a progress callback deletes the batch.
     */
    private function seedJobCounts(UpdatedBatchJobCounts $counts): void
    {
        if ($counts->outstandingFailedJobs === null || $this->progressAtLookup === null) {
            return;
        }

        [, $finishedAt] = $this->progressAtLookup;
        if ($finishedAt !== null && $finishedAt != $counts->finishedAt && $this->loadMissingJobCounts() !== null) {
            return;
        }
        $this->fillJobCounts([
            'totalJobs' => $counts->totalJobs,
            'pendingJobs' => $counts->pendingJobs,
            'failedJobs' => $counts->failedJobs,
            'outstandingFailedJobs' => $counts->outstandingFailedJobs,
            'finishedAt' => $counts->finishedAt,
        ]);
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
        $owner = null;
        $resolver = null;

        $jobs = Collection::wrap($jobs);

        if ($jobs->isEmpty()) {
            return $this->fresh();
        }

        $hasChains = array_any($jobs->all(), static fn ($job) => is_array($job));
        if ($hasChains) {
            $jobs = $this->prepareChainOccurrences($jobs);
        }

        $weights = [];
        $jobs = $jobs->values()->map(function ($job, int $offset) use ($hasChains, &$count, &$weights, &$owner, &$resolver) {
            if (is_array($job)) {
                $weights[$offset] = count($job);
                $count += $weights[$offset];

                $first = array_shift($job);

                if (isset($this->options['queue'])) {
                    $first->allOnQueue($this->options['queue']);
                }

                if (isset($this->options['connection'])) {
                    $first->allOnConnection($this->options['connection']);
                }

                $job = $first->chain($job);
            } else {
                if (! $hasChains) {
                    $job = $this->prepareBatchedJob($job);
                }

                ++$count;
            }

            if (array_key_exists('viaOutbox', $this->options) || array_key_exists('outboxDatabase', $this->options)) {
                // Preserve initialized nulls as well as explicit values on each job.
                $properties = get_object_vars($job);

                foreach (['viaOutbox', 'outboxDatabase'] as $property) {
                    if (array_key_exists($property, $this->options) && ! array_key_exists($property, $properties)) {
                        $job->{$property} = $this->options[$property];
                    }
                }
            }

            // The counter transaction supplies the implicit SQL owner; declared owners must not bypass it.
            if (($database = $job->outboxDatabase ?? null) !== null
                && ($job->viaOutbox ?? null) !== false
                && $this->repository instanceof DatabaseBatchRepository) {
                $owner ??= $this->repository->getConnection()->getNameWithReadWriteType();
                /** @var ConnectionResolverInterface $resolver */
                $resolver ??= Container::getInstance()->make('db');
                $selectedOwner = $resolver->connection($database)->getNameWithReadWriteType();

                if ($selectedOwner !== $owner) {
                    throw new LogicException("Outbox database [{$selectedOwner}] does not match the batch database [{$owner}]. Use the batch database or omit the explicit outbox owner.");
                }
            }

            return $job;
        });

        $jobs = $jobs->all();
        $queue = $this->queue->connection($this->options['connection'] ?? null);
        $destination = $this->options['queue'] ?? null;
        $database = $this->repository instanceof DatabaseBatchRepository ? $this->repository->getConnection() : null;
        $owner = $database?->getNameWithReadWriteType();
        $transactions = $database instanceof Connection ? $database->getTransactionManager() : null;
        if ($database !== null && ($transactions?->hasApplicableTransaction() ?? true)) {
            $ownerHasTransaction = $database instanceof Connection ? $database->hasApplicableTransaction() : $database->transactionLevel() > 0;
            if ($queue instanceof EnqueuesBatchJobs) {
                $queue->ensureBatchCanBeEnqueued($jobs, $owner ?? '', $ownerHasTransaction);
            } elseif ($ownerHasTransaction && array_any($jobs, static fn ($job) => ($job->afterCommit ?? null) === false)) {
                throw new LogicException("A batched job cannot use beforeCommit() while its batch database [{$owner}] has an open transaction.");
            }
        }

        $release = function (array $offsets) use ($weights, $database): void {
            if ($offsets === [] || ! $this->repository instanceof DatabaseBatchRepository) {
                return;
            }
            $count = count($offsets);
            foreach ($offsets as $offset) {
                $count += ($weights[$offset] ?? 1) - 1;
            }
            $counts = $this->repository->releaseJobs($this->id, $count);
            if ($counts !== null) {
                $notify = fn () => $this->invokeCompletionCallbacks($counts);
                $database instanceof Connection ? $database->afterCommitOrNow($notify) : $notify();
            }
        };

        $failure = $this->repository->transaction(function () use ($jobs, $count, $queue, $destination, $database, $owner, $release) {
            try {
                $this->repository->incrementTotalJobs($this->id, $count);
            } catch (BatchNotFoundException) {
                // An after-response dispatch can outlive the transaction that created its batch.
                return;
            }

            if (! $queue instanceof EnqueuesBatchJobs) {
                $send = static fn () => $queue->bulk($jobs, '', $destination);
                if ($database !== null) {
                    Container::getInstance()->make('db.transactions')->addCallback($send, $owner ?? '');
                } else {
                    $send();
                }
                return null;
            }

            $result = $queue->bulkForBatch($jobs, $release, '', $destination, $owner);
            if ($result->failure !== null) {
                if (count($result->rejected) === count($jobs)) {
                    throw $result->failure;
                }
                $release($result->rejected);
            }

            return $result->failure;
        });

        if ($failure !== null) {
            throw $failure;
        }

        return $this->fresh();
    }

    /**
     * Enroll a standalone job or chain head in the batch.
     *
     * Clone an enrolled job so its running handler keeps its batch, chain and position.
     */
    protected function prepareBatchedJob(object $job): object
    {
        $job = $job instanceof Closure ? CallQueuedClosure::create($job) : $job;
        if (isset($job->batchId)) {
            $job = clone $job;
        }

        $job->batchPositionId = null;

        return $job->withBatchId($this->id);
    }

    /**
     * Prepare chain occurrences before applying routing and outbox defaults.
     *
     * Enroll heads first so using a fresh head as another chain's tail preserves the caller's head.
     */
    protected function prepareChainOccurrences(Collection $jobs): Collection
    {
        return $jobs->map(function ($entry) {
            if (! is_array($entry)) {
                return $this->prepareBatchedJob($entry);
            }

            $chain = array_values($entry);
            $chain[0] = $this->prepareBatchedJob($chain[0]);

            return $chain;
        })->map(function ($entry) {
            if (! is_array($entry)) {
                return $entry;
            }

            $chain = $this->prepareBatchedChain($entry)->values()->all();
            foreach ($chain as $index => $job) {
                if ($index !== 0) {
                    $chain[$index] = clone $job;
                    $chain[$index]->batchPositionId = (string) Str::uuid();
                }
            }

            return $chain;
        });
    }

    /**
     * Prepare a chain that exists within the jobs being added.
     */
    protected function prepareBatchedChain(array $chain): Collection
    {
        return (new Collection($chain))->map(function ($job) {
            $job = $job instanceof Closure ? CallQueuedClosure::create($job) : $job;

            if (($job->batchId ?? null) === $this->id) {
                return $job;
            }
            if (isset($job->batchId)) {
                $job = clone $job;
            }

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
     * Get the job's original queue ID.
     *
     * Horizon retries preserve this ID in the payload. It identifies the job for counting unless it has a chain position.
     *
     * @internal
     */
    public static function jobIdFor(Job $job): ?string
    {
        return $job->payload()['batchJobId'] ?? $job->uuid();
    }

    /**
     * Get the ID the batch records for the given command while it is processed.
     *
     * @internal
     */
    public static function jobIdForCommand(object $command): ?string
    {
        if (isset($command->batchPositionId)) {
            return $command->batchPositionId;
        }

        if (isset(static::$commandJobIds[$command])) {
            return static::$commandJobIds[$command];
        }

        // Only InteractsWithQueue makes $job the queued job; another job property belongs to the application.
        return isset(class_uses_recursive($command)[InteractsWithQueue::class]) && $command->job instanceof Job
            ? static::jobIdFor($command->job)
            : null;
    }

    /**
     * Remember the batch job ID of a command that cannot reach its queued job.
     *
     * Commands without InteractsWithQueue have no queued job, and the IDs are held weakly so they never travel
     * with the command or outlive it.
     *
     * @internal
     */
    public static function rememberJobIdForCommand(object $command, Job $job): void
    {
        if (($jobId = static::jobIdFor($job)) !== null) {
            static::$commandJobIds ??= new WeakMap;
            static::$commandJobIds[$command] = $jobId;
        }
    }

    /**
     * Compare a chain assertion's expected job with its serialized occurrence.
     *
     * @internal
     */
    public static function matchesChainedJob(mixed $expected, string $serialized): bool
    {
        if (serialize($expected) === $serialized) {
            return true;
        }

        if (! is_object($expected) || ! isset(class_uses_recursive($expected)[Batchable::class])) {
            return false;
        }

        // Read only the framework's position without restoring the job's queued models.
        $properties = (array) unserialize($serialized, ['allowed_classes' => false]);
        if (! is_string($properties['batchPositionId'] ?? null)) {
            return false;
        }

        $expected = clone $expected;
        $expected->batchPositionId = $properties['batchPositionId'];

        return serialize($expected) === $serialized;
    }

    /**
     * Record that a job within the batch finished successfully, executing any callbacks if necessary.
     */
    public function recordSuccessfulJob(string $jobId): void
    {
        $counts = $this->decrementPendingJobs($jobId);

        if ($counts === null) {
            return;
        }

        $this->seedJobCounts($counts);

        if ($this->isFirstJobProcessed($counts)) {
            $container = Container::getInstance();

            if ($container->bound(Dispatcher::class)) {
                $events = $container->make(Dispatcher::class);

                if ($events->hasListeners(BatchStarted::class)) {
                    $events->dispatch(new BatchStarted($this));
                }
            }
        }

        if ($this->hasProgressCallbacks()) {
            $this->invokeCallbacks('progress');
        }

        $this->invokeCompletionCallbacks($counts);
    }

    /**
     * Notify completion reached by either a job result or a confirmed withdrawal.
     */
    protected function invokeCompletionCallbacks(UpdatedBatchJobCounts $counts): void
    {
        if ($counts->pendingJobs === 0) {
            if ($counts->completedFirstPass === null) {
                $this->repository->markAsFinished($this->id);
            }

            $container = Container::getInstance();

            if ($container->bound(Dispatcher::class)) {
                $events = $container->make(Dispatcher::class);

                if ($events->hasListeners(BatchFinished::class)) {
                    $batch = $this->fresh()?->loadMissingJobCounts();
                    if ($batch === null && $this->progressAtLookup !== null) {
                        // A delivery correction can earn completion after the originating lookup.
                        $this->seedJobCounts($counts);
                    }
                    if ($batch === null || $counts->totalJobs === null || $batch->totalJobs === $counts->totalJobs) {
                        $events->dispatch(new BatchFinished($batch ?? $this));
                    }
                }
            }
        }

        if ($counts->pendingJobs === 0 && $this->hasThenCallbacks()) {
            $this->invokeCallbacks('then', null, $counts->totalJobs);
        }

        $this->markAsFinishedIfOnlyFailuresRemain($counts);

        if ($counts->allJobsHaveRanExactlyOnce() && $this->hasFinallyCallbacks()) {
            $this->invokeCallbacks('finally', null, $counts->totalJobs);
        }
    }

    /**
     * Decrement the pending jobs for the batch.
     */
    public function decrementPendingJobs(string $jobId): ?UpdatedBatchJobCounts
    {
        return $this->repository->decrementPendingJobs($this->id, $jobId);
    }

    /**
     * Invoke the callbacks of the given type.
     *
     * @param null|int $expectedTotalJobs skip completion callbacks when the snapshot's total no longer matches
     */
    protected function invokeCallbacks(string $type, ?Throwable $e = null, ?int $expectedTotalJobs = null): void
    {
        $batch = $this->fresh()?->loadMissingJobCounts();

        if ($batch === null || ($expectedTotalJobs !== null && $batch->totalJobs !== $expectedTotalJobs)) {
            return;
        }

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
     * Determine if the job's counting identity has an outstanding failure.
     */
    public function hasFailedJob(string $jobId): bool
    {
        return $this->repository->hasFailedJob($this->id, $jobId);
    }

    /**
     * Record that a job within the batch failed to finish successfully, executing any callbacks if necessary.
     */
    public function recordFailedJob(string $jobId, ?Throwable $e, ?string $retryId = null): void
    {
        $counts = $this->incrementFailedJobs($jobId, $retryId);

        if ($counts === null) {
            return;
        }

        $this->seedJobCounts($counts);

        if ($this->isFirstJobProcessed($counts)) {
            $container = Container::getInstance();

            if ($container->bound(Dispatcher::class)) {
                $events = $container->make(Dispatcher::class);

                if ($events->hasListeners(BatchStarted::class)) {
                    $events->dispatch(new BatchStarted($this));
                }
            }
        }

        if ($counts->failedJobs === 1 && ! $this->allowsFailures()) {
            $this->cancel($e);
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

        $this->markAsFinishedIfOnlyFailuresRemain($counts);

        if ($counts->allJobsHaveRanExactlyOnce() && $this->hasFinallyCallbacks()) {
            $this->invokeCallbacks('finally', null, $counts->totalJobs);
        }
    }

    /**
     * Increment the failed jobs for the batch.
     */
    public function incrementFailedJobs(string $jobId, ?string $retryId = null): ?UpdatedBatchJobCounts
    {
        return $this->repository->incrementFailedJobs($this->id, $jobId, $retryId);
    }

    /**
     * Mark a batch that allows failures as finished once every remaining job has failed.
     */
    protected function markAsFinishedIfOnlyFailuresRemain(UpdatedBatchJobCounts $counts): void
    {
        // Repositories that report completedFirstPass already finished the batch in their locked update.
        if ($counts->completedFirstPass !== null) {
            return;
        }

        if ($this->allowsFailures() && $counts->pendingJobs > 0 && $counts->allJobsHaveRanExactlyOnce()) {
            $this->repository->markAsFinished($this->id);
        }
    }

    /**
     * Determine if this is the first job processed in the batch.
     */
    protected function isFirstJobProcessed(UpdatedBatchJobCounts $counts): bool
    {
        return ($counts->totalJobs ?? $this->totalJobs) - $counts->pendingJobs + $counts->failedJobs === 1;
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
    public function cancel(?Throwable $exception = null): void
    {
        $this->repository->cancel($this->id);

        $container = Container::getInstance();

        if ($container->bound(Dispatcher::class)) {
            $events = $container->make(Dispatcher::class);

            if ($events->hasListeners(BatchCanceled::class)) {
                $batch = $this->fresh()?->loadMissingJobCounts() ?? $this->loadMissingJobCounts();
                if ($batch !== null) {
                    $events->dispatch(new BatchCanceled($batch, $exception));
                }
            }
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
            if (Coroutine::causedByCancellation($e)) {
                throw $e;
            }

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
