<?php

declare(strict_types=1);

namespace Hypervel\Queue;

use Closure;
use DateInterval;
use DateTimeInterface;
use Hypervel\Bus\Batchable;
use Hypervel\Bus\DispatchLockContext;
use Hypervel\Container\Container as GlobalContainer;
use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Contracts\Encryption\Encrypter;
use Hypervel\Contracts\Events\Dispatcher as EventDispatcher;
use Hypervel\Contracts\Queue\ShouldBeEncrypted;
use Hypervel\Contracts\Queue\ShouldQueueAfterCommit;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Database\DatabaseTransactionsManager;
use Hypervel\Database\QueryException;
use Hypervel\Log\Context\Repository as ContextRepository;
use Hypervel\Queue\Attributes\Backoff;
use Hypervel\Queue\Attributes\CountCrashesAsExceptions;
use Hypervel\Queue\Attributes\Delay;
use Hypervel\Queue\Attributes\DeleteWhenMissingModels;
use Hypervel\Queue\Attributes\FailOnTimeout;
use Hypervel\Queue\Attributes\MaxExceptions;
use Hypervel\Queue\Attributes\ReadsQueueAttributes;
use Hypervel\Queue\Attributes\RetryFor;
use Hypervel\Queue\Attributes\Timeout;
use Hypervel\Queue\Attributes\Tries;
use Hypervel\Queue\Concerns\StagesOutboxJobs;
use Hypervel\Queue\Events\JobPayloadFinalizing;
use Hypervel\Queue\Events\JobQueued;
use Hypervel\Queue\Events\JobQueueing;
use Hypervel\Queue\Events\JobQueueingCanceled;
use Hypervel\Queue\Events\JobQueueingFailed;
use Hypervel\Support\Attributes\BootOnly;
use Hypervel\Support\Attributes\BootOrTestsOnly;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Collection;
use Hypervel\Support\Facades\Context;
use Hypervel\Support\FailureReporter;
use Hypervel\Support\InteractsWithTime;
use Hypervel\Support\Queue\Concerns\ResolvesQueueRoutes;
use Hypervel\Support\Str;
use LogicException;
use ReflectionMethod;
use RuntimeException;
use Throwable;
use UnitEnum;

use function Hypervel\Support\enum_value;

use const JSON_UNESCAPED_UNICODE;

abstract class Queue implements EnqueuesBatchJobs
{
    use StagesOutboxJobs;
    use InteractsWithTime;
    use ReadsQueueAttributes;
    use ResolvesQueueRoutes;

    /**
     * The IoC container instance.
     */
    protected Container $container;

    /**
     * The connection name for the queue.
     */
    protected string $connectionName;

    /**
     * The original configuration for the queue.
     */
    protected array $config = [];

    /**
     * Indicates that jobs should be dispatched after the open parent database transactions have committed.
     */
    protected bool $dispatchAfterCommit = false;

    /**
     * Dispatch an after-commit operation through its live queue owner.
     *
     * @var null|Closure(Closure(Queue): mixed): mixed
     */
    protected ?Closure $afterCommitDispatcher = null;

    /**
     * The create payload callbacks.
     *
     * @var callable[]
     */
    protected static array $createPayloadCallbacks = [];

    /**
     * Push a new job onto the queue.
     */
    public function pushOn(UnitEnum|string|null $queue, object|string $job, mixed $data = ''): mixed
    {
        return $this->push($job, $data, $queue); // @phpstan-ignore method.notFound (declared by the Queue contract each driver implements)
    }

    /**
     * Push a new job onto a specific queue after (n) seconds.
     */
    public function laterOn(UnitEnum|string|null $queue, DateInterval|DateTimeInterface|int $delay, object|string $job, mixed $data = ''): mixed
    {
        return $this->later($delay, $job, $data, $queue); // @phpstan-ignore method.notFound (declared by the Queue contract each driver implements)
    }

    /**
     * Push an array of jobs onto the queue.
     */
    public function bulk(array $jobs, mixed $data = '', UnitEnum|string|null $queue = null): mixed
    {
        return $this->enqueueBulk(array_values($jobs), $data, $this->normalizeQueue($queue), null);
    }

    /**
     * Validate the whole addition before enrolling jobs in the caller's transaction.
     *
     * @internal
     */
    public function ensureBatchCanBeEnqueued(array $jobs, string $connection, bool $ownerHasTransaction): void
    {
        if (! $ownerHasTransaction) {
            return;
        }

        $customBulk = $this->usesCustomBatchDelivery();
        if (($customBulk || $this->batchWaitsForOwner($connection))
            && array_any($jobs, fn ($job) => ($job->afterCommit ?? null) === false && ($customBulk || ! $this->shouldStageViaOutbox($job)))) {
            throw new LogicException("A batched job cannot use beforeCommit() while its batch database [{$connection}] has an open transaction.");
        }
    }

    /**
     * Determine whether a direct send can escape its batch's database transaction.
     */
    protected function batchWaitsForOwner(string $connection): bool
    {
        return true;
    }

    /**
     * Preserve custom transport hooks that an optimized batch send would bypass.
     * Queue subclasses may override bulkForBatch() to report their own delivery outcomes.
     */
    protected function usesCustomBatchDelivery(): bool
    {
        return (new ReflectionMethod($this, 'bulk'))->getDeclaringClass()->getName() !== self::class;
    }

    /**
     * Enqueue enrolled jobs and return the delivery outcome when a send fails.
     *
     * @internal
     */
    public function bulkForBatch(
        array $jobs,
        Closure $onDeferredRejection,
        mixed $data = '',
        UnitEnum|string|null $queue = null,
        ?string $batchTransactionConnection = null,
    ): BatchEnqueueResult {
        $attempt = new BatchEnqueueAttempt($onDeferredRejection, $batchTransactionConnection);

        try {
            if ($this->usesCustomBatchDelivery()) {
                $send = static function (self $owner) use ($jobs, $data, $queue, $attempt) {
                    $attempt->attempting(array_keys($jobs));
                    return $owner->bulk($jobs, $data, $queue);
                };
                if ($batchTransactionConnection === null) {
                    $send($this);
                } else {
                    $this->deferBatchEnqueueAfterCommit(
                        $this->container->make('db.transactions'),
                        $jobs,
                        $send,
                        $batchTransactionConnection,
                        static fn (Throwable $failure) => $attempt->releaseRejected($failure, $jobs),
                    );
                }
            } else {
                $this->enqueueBulk($jobs, $data, $this->normalizeQueue($queue), $attempt);
            }
        } catch (Throwable $failure) {
            $result = $attempt->failed($failure, $jobs);
            if ($batchTransactionConnection !== null && count($result->rejected) !== count($jobs)
                && ! $this->transactionCanContinueAfter($failure)) {
                throw $failure;
            }
            return $result;
        }

        return new BatchEnqueueResult;
    }

    /**
     * Check whether a failed query left its transaction able to commit.
     */
    protected function transactionCanContinueAfter(Throwable $failure): bool
    {
        for ($cause = $failure; $cause !== null; $cause = $cause->getPrevious()) {
            if (! $cause instanceof QueryException) {
                continue;
            }

            try {
                $owner = $this->container->make('db')->getHeldConnection($cause->getConnectionName());
                if ($owner !== null && $owner->transactionLevel() > 0) {
                    if (! $owner->inTransaction() || (int) $owner->scalar('select 1', [], false) !== 1) {
                        return false;
                    }
                    // MySQL may still report a transaction after a deadlock until the next statement.
                    if (! $owner->inTransaction()) {
                        return false;
                    }
                }
            } catch (Throwable $probeFailure) {
                if (Coroutine::causedByCancellation($probeFailure)) {
                    throw $probeFailure;
                }

                return false;
            }
        }

        return true;
    }

    /**
     * Stage durable sends before publishing the remaining jobs to this transport.
     */
    protected function enqueueBulk(array $jobs, mixed $data, ?string $queue, ?BatchEnqueueAttempt $attempt): mixed
    {
        $jobs = $this->stageOutboxJobs($jobs, $data, $queue, $attempt);

        foreach ($jobs as $offset => $job) {
            $delay = $this->getJobDelay($job);
            if ($attempt === null) {
                if ($delay === null) {
                    $this->push($job, $data, $queue); // @phpstan-ignore method.notFound (declared by the Queue contract each driver implements)
                } else {
                    $this->later($delay, $job, $data, $queue); // @phpstan-ignore method.notFound (declared by the Queue contract each driver implements)
                }
                continue;
            }

            $send = $this->prepareBatchMember($job, $data, $queue, $delay, $offset);
            $connection = $attempt->batchTransactionConnection;
            if (($connection !== null && $this->batchWaitsForOwner($connection)) || $this->shouldDispatchAfterCommit($job)) {
                $transactions = $this->container->has('db.transactions') ? $this->container->make('db.transactions') : null;
                if ($transactions?->hasApplicableTransaction($connection)) {
                    $later = new BatchEnqueueAttempt($attempt->onDeferredRejection, null);
                    $member = [$offset => $job];
                    $this->deferBatchEnqueueAfterCommit(
                        $transactions,
                        $member,
                        static fn (Queue $owner) => $send($owner, $later),
                        $connection,
                        static fn (Throwable $failure) => $later->releaseRejected($failure, $member),
                    );
                    $attempt->attempting([$offset]);
                    continue;
                }
            }

            $send($this, $attempt);
        }

        return null;
    }

    /**
     * Let a custom sequential transport own its dispatch and retain an unacknowledged send.
     */
    protected function prepareBatchMember(object|string $job, mixed $data, ?string $queue, DateInterval|DateTimeInterface|int|null $delay, int $offset): Closure
    {
        return static function (Queue $owner, BatchEnqueueAttempt $attempt) use ($job, $data, $queue, $delay, $offset): void {
            $attempt->attempting([$offset]);
            if ($delay === null) {
                $owner->push($job, $data, $queue); // @phpstan-ignore method.notFound (declared by the Queue contract each driver implements)
            } else {
                $owner->later($delay, $job, $data, $queue); // @phpstan-ignore method.notFound (declared by the Queue contract each driver implements)
            }
            $attempt->accepted([$offset]);
        };
    }

    /**
     * Give preparation hooks a list and restore the original offsets for delivery accounting.
     */
    protected function prepareBatchJobsWithOffsets(array $jobs, mixed $data, ?string $queue): array
    {
        if (array_is_list($jobs)) {
            return $this->prepareBatchJobs($jobs, $data, $queue);
        }

        return array_combine(array_keys($jobs), $this->prepareBatchJobs(array_values($jobs), $data, $queue));
    }

    /**
     * Prepare payloads and delays for a set-based enqueue operation.
     *
     * @return array<int, array{job: object|string, delay: null|DateInterval|DateTimeInterface|int, payload: string}>
     */
    protected function prepareBatchJobs(array $jobs, mixed $data, ?string $queue): array
    {
        $prepared = [];

        foreach ($jobs as $offset => $job) {
            $delay = $this->getJobDelay($job);
            $prepared[$offset] = [
                'job' => $job,
                'delay' => $delay,
                'payload' => $this->createPayload($job, $queue, $data, $delay),
            ];
        }

        return $prepared;
    }

    /**
     * Get the delay configured on the given job.
     */
    protected function getJobDelay(object|string $job): mixed
    {
        return is_object($job)
            ? $this->getAttributeValue($job, Delay::class, 'delay')
            : null;
    }

    /**
     * Run an operation with its own log context, then restore the caller.
     *
     * @template TReturn
     * @param Closure(): TReturn $callback
     * @return TReturn
     */
    protected function withLogContext(?ContextRepository $context, Closure $callback): mixed
    {
        $previous = CoroutineContext::get(ContextRepository::CONTEXT_KEY);
        $context === null
            ? CoroutineContext::forget(ContextRepository::CONTEXT_KEY)
            : CoroutineContext::set(ContextRepository::CONTEXT_KEY, $context);

        try {
            return $callback();
        } finally {
            $previous === null
                ? CoroutineContext::forget(ContextRepository::CONTEXT_KEY)
                : CoroutineContext::set(ContextRepository::CONTEXT_KEY, $previous);
        }
    }

    /**
     * Create a payload string from the given job and data.
     *
     * @param Closure|object|string $job
     *
     * @throws InvalidPayloadException
     */
    protected function createPayload(array|object|string $job, ?string $queue, mixed $data = '', DateInterval|DateTimeInterface|int|null $delay = null): string
    {
        try {
            if ($job instanceof Closure) {
                $job = CallQueuedClosure::create($job);
            }

            $value = $this->createPayloadArray($job, $queue, $data);

            $value['delay'] = isset($delay)
                ? $this->secondsUntil($delay)
                : null;

            $payload = json_encode($value, JSON_UNESCAPED_UNICODE);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new InvalidPayloadException(
                    sprintf(
                        'Unable to JSON encode payload for job [%s] on queue [%s]. Error (%d): %s',
                        $value['displayName'] ?? 'unknown',
                        $queue,
                        json_last_error(),
                        json_last_error_msg(),
                    ),
                    $value
                );
            }

            return $payload;
        } catch (Throwable $failure) {
            $this->reportForwardedFailure($job, false);

            throw $failure;
        }
    }

    /**
     * Create a payload array from the given job and data.
     */
    protected function createPayloadArray(array|object|string $job, ?string $queue, mixed $data = ''): array
    {
        return is_object($job)
            ? $this->createObjectPayload($job, $queue)
            : $this->createStringPayload($job, $queue, $data);
    }

    /**
     * Create a payload for an object-based queue handler.
     *
     * @throws RuntimeException
     */
    protected function createObjectPayload(object $job, ?string $queue): array
    {
        $payload = [
            'uuid' => (string) Str::uuid(),
            'displayName' => $this->getDisplayName($job),
            // IMPORTANT: Uses Laravel's handler reference for cross-framework queue interoperability.
            'job' => 'Illuminate\Queue\CallQueuedHandler@call',
            'maxTries' => $this->getJobTries($job),
            'maxExceptions' => $this->getAttributeValue($job, MaxExceptions::class, 'maxExceptions'),
            'failOnTimeout' => $this->getAttributeValue($job, FailOnTimeout::class, 'failOnTimeout') ?? false,
            'countCrashesAsExceptions' => $this->getAttributeValue($job, CountCrashesAsExceptions::class, 'countCrashesAsExceptions') ?? false,
            'backoff' => $this->getJobBackoff($job),
            'timeout' => $this->getAttributeValue($job, Timeout::class, 'timeout'),
            'retryUntil' => $this->getJobExpiration($job),
            'deleteWhenMissingModels' => $this->getAttributeValue($job, DeleteWhenMissingModels::class, 'deleteWhenMissingModels') ?? false,
            'data' => [
                'commandName' => $job,
                'command' => $job,
                'batchId' => $job->batchId ?? null,
            ],
            'createdAt' => CarbonImmutable::now()->getTimestamp(),
        ];

        if (isset($job->batchPositionId, class_uses_recursive($job)[Batchable::class])) {
            $payload['data']['batchPositionId'] = $job->batchPositionId;
        }

        $uniqueJobMetadata = DispatchLockContext::peekPayloadMetadata($job);

        $payload = $uniqueJobMetadata === null && (! ContextRepository::hasInstance() || ! ContextRepository::getInstance()->hasHidden('laravel_unique_job_key'))
            ? $this->withCreatePayloadHooks($queue, $payload)
            : Context::scope(
                function () use ($uniqueJobMetadata, $queue, $payload): array {
                    if ($uniqueJobMetadata === null) {
                        // A child without its own lock must not inherit its parent's
                        // ownership and release that lock during missing-model cleanup.
                        Context::forgetHidden([
                            'laravel_unique_job_cache_store',
                            'laravel_unique_job_key',
                            'laravel_unique_job_lock_owner',
                        ]);
                    }

                    return $this->withCreatePayloadHooks($queue, $payload);
                },
                hidden: $uniqueJobMetadata ?? [],
            );

        try {
            $command = $this->jobShouldBeEncrypted($job) && $this->container->has(Encrypter::class)
                ? $this->container->make(Encrypter::class)->encrypt(serialize(clone $job))
                : serialize(clone $job);
        } catch (Throwable $e) {
            if (Coroutine::causedByCancellation($e)) {
                throw $e;
            }

            throw new RuntimeException(
                sprintf('Failed to serialize job of type [%s]: %s', get_class($job), $e->getMessage()),
                0,
                $e
            );
        }

        return array_merge($payload, [
            'data' => array_merge($payload['data'], [
                'commandName' => get_class($job),
                'command' => $command,
            ]),
        ]);
    }

    /**
     * Get the display name for the given job.
     */
    protected function getDisplayName(object $job): string
    {
        return method_exists($job, 'displayName')
            ? $job->displayName()
            : get_class($job);
    }

    /**
     * Get the maximum number of attempts for an object-based queue handler.
     */
    public function getJobTries(mixed $job): mixed
    {
        $tries = $this->getAttributeValue($job, Tries::class, 'tries');

        if (method_exists($job, 'tries')) {
            $tries = $job->tries();
        }

        return $tries;
    }

    /**
     * Get the backoff for an object-based queue handler.
     */
    public function getJobBackoff(mixed $job): mixed
    {
        $backoff = $this->getAttributeValue($job, Backoff::class, 'backoff');

        if (method_exists($job, 'backoff')) {
            $backoff = $job->backoff();
        }

        if (is_null($backoff)) {
            return null;
        }

        return Collection::wrap($backoff)
            ->map(fn ($backoff) => $backoff instanceof DateTimeInterface ? $this->secondsUntil($backoff) : $backoff)
            ->implode(',');
    }

    /**
     * Get the expiration timestamp for an object-based queue handler.
     */
    public function getJobExpiration(mixed $job): mixed
    {
        if (! method_exists($job, 'retryUntil') && ! isset($job->retryUntil)) {
            $retryFor = $this->getAttributeValue($job, RetryFor::class);

            return is_null($retryFor) ? null : $this->availableAt($retryFor);
        }

        $expiration = $job->retryUntil ?? $job->retryUntil();

        return $expiration instanceof DateTimeInterface
            ? $expiration->getTimestamp()
            : $expiration;
    }

    /**
     * Determine if the job should be encrypted.
     */
    protected function jobShouldBeEncrypted(object $job): bool
    {
        if ($job instanceof ShouldBeEncrypted) {
            return true;
        }

        return isset($job->shouldBeEncrypted) && $job->shouldBeEncrypted;
    }

    /**
     * Create a typical, string based queue payload array.
     */
    protected function createStringPayload(array|string $job, ?string $queue, mixed $data): array
    {
        return $this->withCreatePayloadHooks($queue, [
            'uuid' => (string) Str::uuid(),
            'displayName' => is_string($job) ? explode('@', $job)[0] : null,
            'job' => $job,
            // Caller data must not be interpreted as serialized object-job metadata.
            'stringJob' => true,
            'maxTries' => null,
            'maxExceptions' => null,
            'failOnTimeout' => false,
            'backoff' => null,
            'timeout' => null,
            'data' => $data,
            'createdAt' => CarbonImmutable::now()->getTimestamp(),
        ]);
    }

    /**
     * Register a callback to be executed when creating job payloads.
     *
     * The callback persists in a static property for the worker lifetime
     * and runs on every subsequent payload creation across all coroutines.
     * Passing null clears the registry.
     */
    #[BootOnly]
    public static function createPayloadUsing(?callable $callback): void
    {
        if (is_null($callback)) {
            static::$createPayloadCallbacks = [];
        } else {
            static::$createPayloadCallbacks[] = $callback;
        }
    }

    /**
     * Create the given payload using any registered payload hooks.
     */
    protected function withCreatePayloadHooks(?string $queue, array $payload): array
    {
        if (! empty(static::$createPayloadCallbacks)) {
            foreach (static::$createPayloadCallbacks as $callback) {
                $payload = array_merge($payload, $callback($this->getConnectionName(), $queue, $payload));
            }
        }

        return $payload;
    }

    /**
     * Set the dispatcher for after-commit queue operations.
     *
     * Queue connection instances are cached on QueueManager; replacing the
     * dispatcher on a shared connection changes every subsequent after-commit
     * enqueue through it.
     *
     * @param null|Closure(Closure(Queue): mixed): mixed $dispatcher
     * @return $this
     */
    #[BootOrTestsOnly(whenShared: true)]
    public function setAfterCommitDispatcher(?Closure $dispatcher): static
    {
        $this->afterCommitDispatcher = $dispatcher;

        return $this;
    }

    /**
     * Enqueue a job using the given callback.
     *
     * The callback receives the queue that owns the operation first so deferred
     * pooled dispatch never retains a borrowed queue.
     *
     * @param Closure|object|string $job
     */
    protected function enqueueUsing(object|string $job, string $payload, ?string $queue, DateInterval|DateTimeInterface|int|null $delay, callable $callback): mixed
    {
        try {
            $stageViaOutbox = $this->shouldStageViaOutbox($job);
        } catch (Throwable $exception) {
            $this->raiseJobQueueingFailedEvent($queue, $job, $payload, $delay, $exception, sendAttempted: false);

            throw $exception;
        }

        if ($stageViaOutbox) {
            return $this->enqueueViaOutbox($job, $payload, $queue, $delay);
        }

        if ($this->shouldDispatchAfterCommit($job)
            && $this->container->has('db.transactions')
        ) {
            /** @var DatabaseTransactionsManager $transactions */
            $transactions = $this->container->make('db.transactions');

            if ($transactions->callbackApplicableTransactions()->isNotEmpty()) {
                $this->deferEnqueueAfterCommit(
                    $transactions,
                    $job,
                    static fn (Queue $owner) => $owner->enqueueNow($job, $payload, $queue, $delay, $callback),
                );

                return null;
            }
        }

        return $this->enqueueNow($job, $payload, $queue, $delay, $callback);
    }

    /**
     * Defer an enqueue operation until the applicable transaction commits.
     *
     * @param Closure(Queue): mixed $enqueue
     */
    protected function deferEnqueueAfterCommit(
        DatabaseTransactionsManager $transactions,
        object|string $job,
        Closure $enqueue,
    ): void {
        $this->deferBatchEnqueueAfterCommit($transactions, [$job], $enqueue);
    }

    /**
     * Defer a batch enqueue operation until the applicable transaction commits.
     *
     * @param array<int, object|string> $jobs
     * @param Closure(Queue): mixed $enqueue
     */
    protected function deferBatchEnqueueAfterCommit(
        DatabaseTransactionsManager $transactions,
        array $jobs,
        Closure $enqueue,
        ?string $connection = null,
        ?Closure $onFailure = null,
    ): void {
        // Inline work still belongs to its caller, including a failover's next attempt.
        if (! $transactions->hasApplicableTransaction($connection)) {
            $enqueue($this);
            return;
        }

        $dispatcher = $this->afterCommitDispatcher;
        $operation = $dispatcher === null ? fn () => $enqueue($this) : static fn () => $dispatcher($enqueue);
        $commit = $onFailure === null ? static fn () => self::runDeferredEnqueue($jobs, $operation)
            : static fn () => self::runDeferredEnqueue($jobs, static function () use ($operation, $onFailure) {
                try {
                    return $operation();
                } catch (Throwable $failure) {
                    try {
                        throw $failure;
                    } finally {
                        $onFailure($failure);
                    }
                }
            });

        foreach ($jobs as $job) {
            $this->addJobRollbackCallback($transactions, $job, $connection);
        }
        $transactions->addCallback($commit, $connection);

        foreach ($jobs as $job) {
            if (is_object($job)) {
                DispatchLockContext::delegate($job);
            }
        }
    }

    /**
     * Run deferred work with its dispatch locks and optional deferredDispatchFailed job hook.
     *
     * @param array<int, object|string> $jobs
     */
    protected static function runDeferredEnqueue(array $jobs, Closure $operation): mixed
    {
        try {
            foreach ($jobs as $job) {
                if (is_object($job)) {
                    DispatchLockContext::claim($job);
                }
            }

            // Reacquiring a pooled queue can fail before its enqueue callback runs.
            try {
                return $operation();
            } finally {
                foreach ($jobs as $job) {
                    if (is_object($job)) {
                        DispatchLockContext::release($job);
                    }
                }
            }
        } catch (Throwable $exception) {
            $job = reset($jobs);

            if (Coroutine::causedByCancellation($exception)
                || count($jobs) !== 1
                || ! is_object($job)
                || ! method_exists($job, 'deferredDispatchFailed')
            ) {
                throw $exception;
            }

            return $job->deferredDispatchFailed($exception);
        }
    }

    /**
     * Enqueue a job immediately using the given callback.
     */
    protected function enqueueNow(object|string $job, string $payload, ?string $queue, DateInterval|DateTimeInterface|int|null $delay, callable $callback, ?BatchEnqueueAttempt $attempt = null, int $offset = 0): mixed
    {
        $sendAttempted = false;

        try {
            $payload = $this->finalizePayloadForQueueing($queue, $job, $payload, $delay);
            $this->raiseJobQueueingEvent($queue, $job, $payload, $delay);
            $storedPayload = $this->preparePayloadForPush($job, $payload, $queue);
            $sendAttempted = true;
            $attempt?->attempting([$offset]);
            $jobId = $callback($this, $storedPayload, $queue, $delay);
            $attempt?->accepted([$offset]);
        } catch (Throwable $exception) {
            $this->raiseJobQueueingFailedEvent($queue, $job, $payload, $delay, $exception, $sendAttempted);

            throw $exception;
        }

        $this->acceptDispatchLocks($job);
        $exception = null;

        // The job is stored, so a canceled post-storage step must not skip its queued notification.
        try {
            $this->handlePayloadPushed($storedPayload, $queue);
        } catch (Throwable $throwable) {
            $exception = $throwable;
        }

        try {
            $this->raiseJobQueuedEvent($queue, $jobId, $job, $payload, $delay);
        } catch (Throwable $throwable) {
            $exception = FailureReporter::preferCancellation($exception, $throwable);
        }

        if ($exception !== null) {
            $this->reportForwardedFailure($job, true);
            throw $exception;
        }

        return $jobId;
    }

    /**
     * Prepare a finalized payload for storage without changing lifecycle event correlation.
     */
    protected function preparePayloadForPush(object|string $job, string $payload, ?string $queue): string
    {
        return $payload;
    }

    /**
     * Handle a payload after the queue has accepted it.
     */
    protected function handlePayloadPushed(string $payload, ?string $queue): void
    {
    }

    /**
     * Notify every batch member and return the first ordinary notification failure.
     *
     * A canceled notification does not skip the remaining members; the first
     * cancellation is rethrown once every member has been notified, and the
     * ordinary failure it displaces is reported.
     *
     * @template TMember
     * @param array<array-key, TMember> $members
     * @param Closure(TMember): void ...$notifications
     *
     * @throws Throwable the first notification cancellation
     */
    protected function notifyBatchMembers(array $members, Closure ...$notifications): ?Throwable
    {
        $exception = null;
        $cancellation = null;

        foreach ($members as $member) {
            foreach ($notifications as $notification) {
                try {
                    $notification($member);
                } catch (Throwable $throwable) {
                    if (Coroutine::causedByCancellation($throwable)) {
                        $cancellation = Coroutine::preferCancellation($cancellation, $throwable);
                    } else {
                        $exception ??= $throwable;
                    }
                }
            }
        }

        if ($cancellation !== null) {
            if ($exception !== null) {
                FailureReporter::report($exception);
            }

            throw $cancellation;
        }

        return $exception;
    }

    /**
     * Notify every batch member of a failure or cancellation, reporting listener errors so they cannot replace it.
     *
     * @template TMember
     * @param array<array-key, TMember> $members
     * @param Closure(TMember): void $notification
     */
    protected function notifyBatchMembersOfFailure(array $members, Closure $notification): void
    {
        if (($exception = $this->notifyBatchMembers($members, $notification)) !== null) {
            $this->reportSecondaryFailure($exception);
        }
    }

    /**
     * Report a failure that must not replace the primary error, without swallowing cancellation.
     */
    protected function reportSecondaryFailure(Throwable $exception): void
    {
        $reportingFailure = null;

        try {
            if ($this->container->has(ExceptionHandler::class)) {
                $this->container->make(ExceptionHandler::class)->report($exception);

                return;
            }
        } catch (Throwable $reportingFailure) {
            if ($reportingFailure !== $exception && Coroutine::causedByCancellation($reportingFailure)) {
                throw $reportingFailure;
            }
        }

        try {
            error_log((string) $exception . ($reportingFailure !== null && $reportingFailure !== $exception ? PHP_EOL . (string) $reportingFailure : ''));
        } catch (Throwable) {
        }
    }

    /**
     * Finalize an encoded payload immediately before queueing.
     *
     * @param Closure|object|string $job
     */
    protected function finalizePayloadForQueueing(
        ?string $queue,
        object|string $job,
        string $payload,
        DateInterval|DateTimeInterface|int|null $delay,
    ): string {
        if (! $this->container->bound('events')) {
            return $payload;
        }

        /** @var EventDispatcher $events */
        $events = $this->container->make('events');

        if (! $events->hasListeners(JobPayloadFinalizing::class)) {
            return $payload;
        }

        $delay = $delay !== null ? $this->secondsUntil($delay) : null;
        $event = new JobPayloadFinalizing(
            $this->connectionName,
            $queue,
            $job,
            $payload,
            $delay,
        );
        $events->dispatch($event);

        return $event->payload;
    }

    /**
     * Partition jobs by whether they should be deferred until the active transaction commits.
     *
     * @return array{0: array, 1: array}
     */
    protected function partitionJobsByAfterCommit(
        array $jobs,
        ?DatabaseTransactionsManager $transactions
    ): array {
        if ($transactions === null || $transactions->callbackApplicableTransactions()->isEmpty()) {
            return [[], $jobs];
        }

        return (new Collection($jobs))
            ->partition(fn ($job) => $this->shouldDispatchAfterCommit($job))
            ->map(fn ($partition) => $partition->all())
            ->all();
    }

    /**
     * Determine if the job should be dispatched after the open parent database transactions have committed.
     *
     * @param Closure|object|string $job
     */
    protected function shouldDispatchAfterCommit(object|string $job): bool
    {
        if ($job instanceof ShouldQueueAfterCommit) {
            $afterCommit = ! (isset($job->afterCommit) && $job->afterCommit === false);
        } elseif (! $job instanceof Closure && is_object($job) && isset($job->afterCommit)) {
            $afterCommit = $job->afterCommit;
        } else {
            $afterCommit = $this->dispatchAfterCommit;
        }

        if (! $afterCommit) {
            return false;
        }

        $forwarded = CoroutineContext::get(self::OUTBOX_FORWARDED_CONTEXT_KEY);

        return ($forwarded['job'] ?? null) !== $job || ! $forwarded['afterCommitHandled'];
    }

    /**
     * Register a transaction rollback callback that releases the job's locks.
     */
    protected function addJobRollbackCallback(DatabaseTransactionsManager $transactions, object|string $job, ?string $connection = null): void
    {
        $callback = $this->createJobRollbackCallback($job);

        if ($callback !== null) {
            $transactions->addCallbackForRollback($callback, $connection);
        }
    }

    /**
     * Create a callback that releases the job's locks.
     */
    protected function createJobRollbackCallback(object|string $job): ?Closure
    {
        if (! is_object($job) || ! DispatchLockContext::has($job)) {
            return null;
        }

        // Rollback revokes ownership delegated to the commit callback, which can no longer run.
        return static fn () => self::runDeferredEnqueue([$job], static fn () => null);
    }

    /**
     * Mark a job's dispatch locks as accepted by the queue.
     */
    protected function acceptDispatchLocks(object|string $job): void
    {
        if (is_object($job)) {
            DispatchLockContext::accept($job);
        }
    }

    /**
     * Raise the job queueing event.
     *
     * @param Closure|object|string $job
     */
    protected function raiseJobQueueingEvent(?string $queue, object|string $job, string $payload, DateInterval|DateTimeInterface|int|null $delay): void
    {
        if ($this->container->bound('events')) {
            /** @var EventDispatcher $events */
            $events = $this->container->make('events');

            if (! $events->hasListeners(JobQueueing::class)) {
                return;
            }

            $delay = ! is_null($delay) ? $this->secondsUntil($delay) : $delay;

            $events->dispatch(new JobQueueing($this->connectionName, $queue, $job, $payload, $delay));
        }
    }

    /**
     * Raise the job queueing failed event, or the canceled event when cancellation stopped the job.
     *
     * @param Closure|object|string $job
     */
    protected function raiseJobQueueingFailedEvent(
        ?string $queue,
        object|string $job,
        string $payload,
        DateInterval|DateTimeInterface|int|null $delay,
        Throwable $exception,
        bool $sendAttempted = true,
    ): void {
        if (! $sendAttempted) {
            $this->reportForwardedFailure($job, false);
        }

        if (Coroutine::causedByCancellation($exception)) {
            $this->raiseJobQueueingCanceledEvent($queue, $job, $payload, $delay, $exception, $sendAttempted);

            return;
        }

        if ($this->container->bound('events')) {
            /** @var EventDispatcher $events */
            $events = $this->container->make('events');

            if (! $events->hasListeners(JobQueueingFailed::class)) {
                return;
            }

            $delay = ! is_null($delay) ? $this->secondsUntil($delay) : $delay;

            try {
                $events->dispatch(new JobQueueingFailed($this->connectionName, $queue, $job, $payload, $delay, $exception, $sendAttempted));
            } catch (Throwable $throwable) {
                if (Coroutine::causedByCancellation($throwable)) {
                    $this->raiseJobQueueingCanceledEvent($queue, $job, $payload, $delay, $throwable, $sendAttempted, exception: $exception);
                }

                throw $throwable;
            }
        }
    }

    /**
     * Raise the job queueing canceled event.
     *
     * The cancellation is already propagating, so a listener failure is
     * reported instead of replacing it.
     *
     * @param Closure|object|string $job
     */
    protected function raiseJobQueueingCanceledEvent(
        ?string $queue,
        object|string $job,
        string $payload,
        DateInterval|DateTimeInterface|int|null $delay,
        Throwable $cancellation,
        bool $sendAttempted,
        bool $queued = false,
        ?Throwable $exception = null,
    ): void {
        if ($this->container->bound('events')) {
            /** @var EventDispatcher $events */
            $events = $this->container->make('events');

            if (! $events->hasListeners(JobQueueingCanceled::class)) {
                return;
            }

            $delay = ! is_null($delay) ? $this->secondsUntil($delay) : $delay;

            try {
                $events->dispatch(new JobQueueingCanceled(
                    $this->connectionName,
                    $queue,
                    $job,
                    $payload,
                    $delay,
                    $cancellation,
                    $sendAttempted,
                    $queued,
                    $exception,
                ));
            } catch (Throwable $throwable) {
                FailureReporter::report($throwable);
            }
        }
    }

    /**
     * Raise the job queued event.
     *
     * @param Closure|object|string $job
     */
    protected function raiseJobQueuedEvent(?string $queue, mixed $jobId, object|string $job, string $payload, DateInterval|DateTimeInterface|int|null $delay): void
    {
        if ($this->container->bound('events')) {
            /** @var EventDispatcher $events */
            $events = $this->container->make('events');

            if (! $events->hasListeners(JobQueued::class)) {
                return;
            }

            $delay = ! is_null($delay) ? $this->secondsUntil($delay) : $delay;

            try {
                $events->dispatch(new JobQueued($this->connectionName, $queue, $jobId, $job, $payload, $delay));
            } catch (Throwable $throwable) {
                if (Coroutine::causedByCancellation($throwable)) {
                    $this->raiseJobQueueingCanceledEvent($queue, $job, $payload, $delay, $throwable, sendAttempted: true, queued: true);
                }

                throw $throwable;
            }
        }
    }

    /**
     * Normalize an enum queue name without applying defaults or forwarding.
     */
    protected function normalizeQueue(UnitEnum|string|null $queue): ?string
    {
        return $queue instanceof UnitEnum ? (string) enum_value($queue) : $queue;
    }

    /**
     * Get the routed queue name for the given queue.
     */
    protected function resolveQueue(string $queue): string
    {
        return $this->queueRoutes()->forwardedQueue($queue, $this->connectionName ?? null);
    }

    /**
     * Get the container that owns the queue routes.
     */
    protected function queueRoutesContainer(): Container
    {
        return $this->container ?? GlobalContainer::getInstance();
    }

    /**
     * Get the connection name for the queue.
     */
    public function getConnectionName(): string
    {
        return $this->connectionName;
    }

    /**
     * Set the connection name for the queue.
     *
     * Queue connection instances are cached on QueueManager; runtime use
     * races across coroutines and changes every concurrent dispatch
     * through this connection.
     *
     * @return $this
     */
    #[BootOrTestsOnly(whenShared: true)]
    public function setConnectionName(string $name): static
    {
        $this->connectionName = $name;

        return $this;
    }

    /**
     * Get the queue configuration array.
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * Set the queue configuration array.
     *
     * Queue connection instances are cached on QueueManager; runtime use
     * races across coroutines and changes every concurrent dispatch
     * through this connection.
     *
     * @return $this
     */
    #[BootOrTestsOnly(whenShared: true)]
    public function setConfig(array $config): static
    {
        $this->config = $config;
        $this->dispatchViaOutbox = $config['outbox'] ?? false;

        return $this;
    }

    /**
     * Get the container instance being used by the connection.
     */
    public function getContainer(): Container
    {
        return $this->container;
    }

    /**
     * Set the IoC container instance.
     *
     * Queue connection instances are cached on QueueManager; runtime use
     * races across coroutines and changes every concurrent dispatch
     * through this connection.
     */
    #[BootOrTestsOnly(whenShared: true)]
    public function setContainer(Container $container): static
    {
        $this->container = $container;

        return $this;
    }

    /**
     * Flush all static state.
     */
    public static function flushState(): void
    {
        static::$createPayloadCallbacks = [];
    }
}
