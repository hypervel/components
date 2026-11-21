<?php

declare(strict_types=1);

namespace Hypervel\Queue;

use Closure;
use DateInterval;
use DateTimeInterface;
use Hypervel\Bus\Batchable;
use Hypervel\Bus\DispatchLockContext;
use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Queue\IndexAwareQueue;
use Hypervel\Contracts\Queue\Job as JobContract;
use Hypervel\Contracts\Queue\Queue as QueueContract;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Database\DatabaseTransactionRecord;
use Hypervel\Database\DatabaseTransactionsManager;
use Hypervel\Log\Context\Repository as ContextRepository;
use Hypervel\Queue\Events\QueueFailedOver;
use Hypervel\Support\Collection;
use Hypervel\Support\Str;
use RuntimeException;
use Throwable;
use UnitEnum;
use WeakReference;

use function Hypervel\Support\enum_value;

class FailoverQueue extends Queue implements QueueContract, IndexAwareQueue
{
    /**
     * Context key prefix for the queues which failed on the last action.
     *
     * Entries pair a weak owner with its failure list. A dead or different owner
     * means no history, since released object IDs may be reused within a coroutine.
     *
     * Stored in coroutine Context instead of an instance property because this
     * queue is cached in QueueManager::$connections. Instance state would leak
     * across concurrent dispatches.
     */
    protected const string FAILING_QUEUES_CONTEXT_PREFIX = '__queue.failover.failing_queues.';

    /**
     * Create a new failover queue instance.
     */
    public function __construct(
        public QueueManager $manager,
        public Dispatcher $events,
        public array $connections,
        protected bool $dispatchAfterCommit = false
    ) {
    }

    /**
     * Get the size of the queue.
     */
    public function size(UnitEnum|string|null $queue = null): int
    {
        return $this->manager->connection($this->connections[0])->size($this->resolveForwardedQueue($queue));
    }

    /**
     * Get the number of pending jobs.
     */
    public function pendingSize(UnitEnum|string|null $queue = null): int
    {
        return $this->manager->connection($this->connections[0])->pendingSize($this->resolveForwardedQueue($queue));
    }

    /**
     * Get the number of delayed jobs.
     */
    public function delayedSize(UnitEnum|string|null $queue = null): int
    {
        return $this->manager->connection($this->connections[0])->delayedSize($this->resolveForwardedQueue($queue));
    }

    /**
     * Get the number of reserved jobs.
     */
    public function reservedSize(UnitEnum|string|null $queue = null): int
    {
        return $this->manager->connection($this->connections[0])->reservedSize($this->resolveForwardedQueue($queue));
    }

    /**
     * Get the number of jobs across every queue.
     */
    public function totalSize(): int
    {
        return $this->manager->connection($this->connections[0])->totalSize();
    }

    /**
     * Get the number of pending jobs across every queue.
     */
    public function totalPendingSize(): int
    {
        return $this->manager->connection($this->connections[0])->totalPendingSize();
    }

    /**
     * Get the number of delayed jobs across every queue.
     */
    public function totalDelayedSize(): int
    {
        return $this->manager->connection($this->connections[0])->totalDelayedSize();
    }

    /**
     * Get the number of reserved jobs across every queue.
     */
    public function totalReservedSize(): int
    {
        return $this->manager->connection($this->connections[0])->totalReservedSize();
    }

    /**
     * Get the pending jobs for the given queue.
     */
    public function pendingJobs(UnitEnum|string|null $queue = null): Collection
    {
        return $this->manager->connection($this->connections[0])->pendingJobs($this->resolveForwardedQueue($queue));
    }

    /**
     * Get the delayed jobs for the given queue.
     */
    public function delayedJobs(UnitEnum|string|null $queue = null): Collection
    {
        return $this->manager->connection($this->connections[0])->delayedJobs($this->resolveForwardedQueue($queue));
    }

    /**
     * Get the reserved jobs for the given queue.
     */
    public function reservedJobs(UnitEnum|string|null $queue = null): Collection
    {
        return $this->manager->connection($this->connections[0])->reservedJobs($this->resolveForwardedQueue($queue));
    }

    /**
     * Get all pending jobs across every queue.
     */
    public function allPendingJobs(): Collection
    {
        return $this->manager->connection($this->connections[0])->allPendingJobs();
    }

    /**
     * Get all delayed jobs across every queue.
     */
    public function allDelayedJobs(): Collection
    {
        return $this->manager->connection($this->connections[0])->allDelayedJobs();
    }

    /**
     * Get all reserved jobs across every queue.
     */
    public function allReservedJobs(): Collection
    {
        return $this->manager->connection($this->connections[0])->allReservedJobs();
    }

    /**
     * Get the creation timestamp of the oldest pending job, excluding delayed jobs.
     */
    public function creationTimeOfOldestPendingJob(UnitEnum|string|null $queue = null): ?int
    {
        return $this->manager
            ->connection($this->connections[0])
            ->creationTimeOfOldestPendingJob($this->resolveForwardedQueue($queue));
    }

    /**
     * Push a new job onto the queue.
     */
    public function push(object|string $job, mixed $data = '', UnitEnum|string|null $queue = null): mixed
    {
        $queue = $this->resolveForwardedQueue($queue);

        return $this->attemptOnAllConnections(__FUNCTION__, func_get_args(), $job);
    }

    /**
     * Preserve per-job transport overrides in custom queues.
     */
    protected function usesCustomBatchDelivery(): bool
    {
        return static::class !== self::class;
    }

    /**
     * Keep the normal per-job failover order while enrollment waits for its owner.
     */
    protected function enqueueBulk(array $jobs, mixed $data, ?string $queue, ?BatchEnqueueAttempt $attempt): mixed
    {
        if ($attempt === null) {
            return parent::enqueueBulk($jobs, $data, $queue, null);
        }

        $queue = $this->resolveForwardedQueue($queue);
        $transactions = $this->container->has('db.transactions') ? $this->container->make('db.transactions') : null;
        foreach ($jobs as $offset => $job) {
            $delay = $this->getJobDelay($job);
            $method = $delay === null ? 'push' : 'later';
            $arguments = $delay === null ? [$job, $data, $queue] : [$delay, $job, $data, $queue];
            $owner = $attempt->batchTransactionConnection;
            $explicit = $this->shouldDispatchAfterCommit($job);
            $onFailure = static function (?bool $queued) use ($attempt, $offset): void {
                if ($queued === true) {
                    $attempt->accepted([$offset]);
                } elseif ($queued === null) {
                    $attempt->attempting([$offset]);
                }
            };

            if ($transactions !== null && ($owner !== null || $explicit)
                && $this->deferUntilAllTransactionsCommit(
                    $transactions,
                    $method,
                    $arguments,
                    $job,
                    $explicit ? null : $owner,
                    $owner,
                    static fn () => ($attempt->onDeferredRejection)([$offset]),
                )) {
                $attempt->attempting([$offset]);
                continue;
            }

            $this->attemptOnConnections($method, $arguments, $job, false, $onFailure);
            $attempt->accepted([$offset]);
        }

        return null;
    }

    /**
     * Push a raw payload onto the queue.
     */
    public function pushRaw(string $payload, UnitEnum|string|null $queue = null, array $options = []): mixed
    {
        $queue = $this->resolveForwardedQueue($queue);

        return $this->attemptOnAllConnections(__FUNCTION__, func_get_args());
    }

    /**
     * Push a new job onto the queue after (n) seconds.
     */
    public function later(DateInterval|DateTimeInterface|int $delay, object|string $job, mixed $data = '', UnitEnum|string|null $queue = null): mixed
    {
        $queue = $this->resolveForwardedQueue($queue);

        return $this->attemptOnAllConnections(__FUNCTION__, func_get_args(), $job);
    }

    /**
     * Pop the next job off of the queue.
     */
    public function pop(UnitEnum|string|null $queue = null, int $index = 0): ?JobContract
    {
        $queue = $this->resolveForwardedQueue($queue);
        $connection = $this->manager->connection($this->connections[0]);

        return $connection instanceof IndexAwareQueue
            ? $connection->pop($queue, $index)
            : $connection->pop($queue);
    }

    /**
     * Resolve forwards owned by this failover connection.
     */
    protected function resolveForwardedQueue(UnitEnum|string|null $queue): ?string
    {
        $queue = $this->normalizeQueue($queue);

        // Unscoped forwards belong to the storage driver; applying them here would forward twice.
        return $queue === null ? null : $this->queueRoutes()->forwardedQueueForConnection($queue, $this->connectionName ?? null);
    }

    /**
     * Preserve failover attempts instead of capturing an undecided destination.
     */
    protected function shouldStageViaOutbox(object|string $job): bool
    {
        return false;
    }

    /**
     * Degrade selected outbox delivery to an after-commit failover attempt.
     */
    protected function shouldDispatchAfterCommit(object|string $job): bool
    {
        return $this->shouldDispatchViaOutbox($job) || parent::shouldDispatchAfterCommit($job);
    }

    /**
     * Attempt the given method on all connections.
     *
     * @throws Throwable
     */
    protected function attemptOnAllConnections(string $method, array $arguments, object|string|null $job = null): mixed
    {
        if (
            $job !== null
            && $this->shouldDispatchAfterCommit($job)
            && $this->container->has('db.transactions')
        ) {
            /** @var DatabaseTransactionsManager $transactions */
            $transactions = $this->container->make('db.transactions');

            if ($this->deferUntilAllTransactionsCommit($transactions, $method, $arguments, $job)) {
                return null;
            }
        }

        return $this->attemptOnConnections($method, $arguments, $job);
    }

    /**
     * Forward an operation to the first available child connection.
     */
    protected function attemptOnConnections(
        string $method,
        array $arguments,
        object|string|null $job,
        bool $afterCommitHandled = false,
        ?Closure $onFailure = null,
    ): mixed {
        // Each child builds its own transport UUID, even if the previous child already accepted the job.
        if (is_object($job) && isset($job->batchId) && ! isset($job->batchPositionId)
            && isset(class_uses_recursive($job)[Batchable::class])) {
            $job->batchPositionId = (string) Str::uuid();
        }

        $contextKey = self::FAILING_QUEUES_CONTEXT_PREFIX . spl_object_id($this);
        $state = CoroutineContext::get($contextKey);
        $failingQueues = $state !== null && $state[0]->get() === $this ? $state[1] : [];

        [$lastException, $failedQueues] = [null, []];
        $forwarded = CoroutineContext::get(self::OUTBOX_FORWARDED_CONTEXT_KEY);

        if ($job !== null && ($forwarded['job'] ?? null) !== $job) {
            $forwarded = ['job' => $job, 'afterCommitHandled' => $afterCommitHandled];
        }

        $onFailure ??= $forwarded['onFailure'] ?? null;
        $outcome = false;
        if ($onFailure !== null) {
            $forwarded['onFailure'] = static function (?bool $queued) use (&$childOutcome): void {
                $childOutcome = $queued;
            };
        }

        try {
            foreach ($this->connections as $connection) {
                /** @var null|bool $childOutcome The forwarded child may report its outcome before throwing. */
                $childOutcome = null;
                try {
                    return $job === null
                        ? $this->manager->connection($connection)->{$method}(...$arguments)
                        : $this->withOutboxForwarding(
                            $forwarded,
                            fn () => $this->manager->connection($connection)->{$method}(...$arguments),
                        );
                } catch (Throwable $e) {
                    // A later rejection cannot undo an earlier accepted or unacknowledged send.
                    if ($childOutcome === true || ($childOutcome === null && $outcome === false)) {
                        $outcome = $childOutcome;
                    }
                    if (Coroutine::causedByCancellation($e) || ! $this->transactionCanContinueAfter($e)) {
                        throw $e;
                    }

                    $lastException = $e;

                    $failedQueues[] = $connection;

                    if ($job !== null
                        && ! in_array($connection, $failingQueues, true)
                        && $this->events->hasListeners(QueueFailedOver::class)
                    ) {
                        $this->events->dispatch(new QueueFailedOver(
                            $connection instanceof UnitEnum ? (string) enum_value($connection) : $connection,
                            $job,
                            $e,
                        ));
                    }
                }
            }
            throw $lastException ?? new RuntimeException('All failover queue connections failed.');
        } catch (Throwable $failure) {
            try {
                throw $failure;
            } finally {
                $onFailure?->__invoke($outcome);
            }
        } finally {
            CoroutineContext::set($contextKey, [WeakReference::create($this), $failedQueues]);
        }
    }

    /**
     * Publish once every participating transaction commits, or discard on the first rollback.
     */
    protected function afterConnectionsCommit(DatabaseTransactionsManager $transactions, array $connections, Closure $commit, Closure $rollback): void
    {
        $pending = array_fill_keys($connections, true);
        $settled = false;

        // Register rollbacks first because a commit callback may run inline.
        foreach ($connections as $connection) {
            $transactions->addCallbackForRollback(static function () use (&$settled, $rollback, $connection): void {
                if (! $settled) {
                    $settled = true;
                    $rollback($connection);
                }
            }, $connection);
        }
        foreach ($connections as $connection) {
            $transactions->addCallback(static function () use (&$settled, &$pending, $commit, $connection): void {
                unset($pending[$connection]);
                if (! $settled && $pending === []) {
                    $settled = true;
                    $commit();
                }
            }, $connection);
        }
    }

    /**
     * Defer the failover attempt until every applicable transaction commits.
     */
    protected function deferUntilAllTransactionsCommit(
        DatabaseTransactionsManager $transactions,
        string $method,
        array $arguments,
        object|string $job,
        ?string $selectedConnection = null,
        ?string $enrollmentConnection = null,
        ?Closure $reject = null,
    ): bool {
        $connections = $transactions->callbackApplicableTransactions()
            ->map(static fn (DatabaseTransactionRecord $transaction): string => $transaction->connection)
            ->uniqueStrict()
            ->values()
            ->all();

        if ($connections === []) {
            return false;
        }

        if (($selectedConnection ??= $this->resolveOutboxCommitConnection($transactions, $job)) !== null) {
            $connections = [$selectedConnection];
        }

        $context = ContextRepository::hasInstance() ? ContextRepository::getInstance()->replicate() : null;
        $releaseLocks = $this->createJobRollbackCallback($job);
        $this->afterConnectionsCommit(
            $transactions,
            $connections,
            function () use ($job, $context, $selectedConnection, $method, $arguments, $reject): void {
                $onFailure = $reject === null ? null : static function (?bool $queued) use ($reject): void {
                    if ($queued === false) {
                        $reject();
                    }
                };
                // Child payload hooks must see the dispatch-time log context.
                self::runDeferredEnqueue([$job], fn () => $this->withLogContext($context, fn () => $selectedConnection !== null || $reject !== null
                    ? $this->attemptOnConnections($method, $arguments, $job, true, $onFailure)
                    : $this->attemptOnAllConnections($method, $arguments, $job)));
            },
            static function (string $connection) use ($releaseLocks, $enrollmentConnection, $reject): void {
                try {
                    $releaseLocks?->__invoke();
                } finally {
                    if ($connection !== $enrollmentConnection) {
                        $reject?->__invoke();
                    }
                }
            },
        );

        if (is_object($job)) {
            DispatchLockContext::delegate($job);
        }

        return true;
    }
}
