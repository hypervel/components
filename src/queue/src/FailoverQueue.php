<?php

declare(strict_types=1);

namespace Hypervel\Queue;

use DateInterval;
use DateTimeInterface;
use Hypervel\Bus\DispatchLockContext;
use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Queue\IndexAwareQueue;
use Hypervel\Contracts\Queue\Job as JobContract;
use Hypervel\Contracts\Queue\Queue as QueueContract;
use Hypervel\Database\DatabaseTransactionRecord;
use Hypervel\Database\DatabaseTransactionsManager;
use Hypervel\Queue\Events\QueueFailedOver;
use Hypervel\Support\Collection;
use RuntimeException;
use Swoole\Coroutine\CanceledException;
use Throwable;

class FailoverQueue extends Queue implements QueueContract, IndexAwareQueue
{
    /**
     * Context key prefix for the queues which failed on the last action.
     *
     * Scoped per instance via spl_object_id() so multiple failover queues
     * in the same coroutine don't share failure history.
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
    public function size(?string $queue = null): int
    {
        return $this->manager->connection($this->connections[0])->size($queue);
    }

    /**
     * Get the number of pending jobs.
     */
    public function pendingSize(?string $queue = null): int
    {
        return $this->manager->connection($this->connections[0])->pendingSize($queue);
    }

    /**
     * Get the number of delayed jobs.
     */
    public function delayedSize(?string $queue = null): int
    {
        return $this->manager->connection($this->connections[0])->delayedSize($queue);
    }

    /**
     * Get the number of reserved jobs.
     */
    public function reservedSize(?string $queue = null): int
    {
        return $this->manager->connection($this->connections[0])->reservedSize($queue);
    }

    /**
     * Get the number of jobs across every queue.
     */
    public function totalSize(): int
    {
        // Aggregate counts are an optional driver capability outside the core Queue contract.
        return $this->manager->connection($this->connections[0])->totalSize(); // @phpstan-ignore method.notFound
    }

    /**
     * Get the number of pending jobs across every queue.
     */
    public function totalPendingSize(): int
    {
        return $this->manager->connection($this->connections[0])->totalPendingSize(); // @phpstan-ignore method.notFound
    }

    /**
     * Get the number of delayed jobs across every queue.
     */
    public function totalDelayedSize(): int
    {
        return $this->manager->connection($this->connections[0])->totalDelayedSize(); // @phpstan-ignore method.notFound
    }

    /**
     * Get the number of reserved jobs across every queue.
     */
    public function totalReservedSize(): int
    {
        return $this->manager->connection($this->connections[0])->totalReservedSize(); // @phpstan-ignore method.notFound
    }

    /**
     * Get the pending jobs for the given queue.
     */
    public function pendingJobs(?string $queue = null): Collection
    {
        // Inspection remains an optional concrete capability, not part of the core Queue contract.
        return $this->manager->connection($this->connections[0])->pendingJobs($queue); // @phpstan-ignore method.notFound
    }

    /**
     * Get the delayed jobs for the given queue.
     */
    public function delayedJobs(?string $queue = null): Collection
    {
        return $this->manager->connection($this->connections[0])->delayedJobs($queue); // @phpstan-ignore method.notFound
    }

    /**
     * Get the reserved jobs for the given queue.
     */
    public function reservedJobs(?string $queue = null): Collection
    {
        return $this->manager->connection($this->connections[0])->reservedJobs($queue); // @phpstan-ignore method.notFound
    }

    /**
     * Get all pending jobs across every queue.
     */
    public function allPendingJobs(): Collection
    {
        return $this->manager->connection($this->connections[0])->allPendingJobs(); // @phpstan-ignore method.notFound
    }

    /**
     * Get all delayed jobs across every queue.
     */
    public function allDelayedJobs(): Collection
    {
        return $this->manager->connection($this->connections[0])->allDelayedJobs(); // @phpstan-ignore method.notFound
    }

    /**
     * Get all reserved jobs across every queue.
     */
    public function allReservedJobs(): Collection
    {
        return $this->manager->connection($this->connections[0])->allReservedJobs(); // @phpstan-ignore method.notFound
    }

    /**
     * Get the creation timestamp of the oldest pending job, excluding delayed jobs.
     */
    public function creationTimeOfOldestPendingJob(?string $queue = null): ?int
    {
        return $this->manager
            ->connection($this->connections[0])
            ->creationTimeOfOldestPendingJob($queue);
    }

    /**
     * Push a new job onto the queue.
     */
    public function push(object|string $job, mixed $data = '', ?string $queue = null): mixed
    {
        return $this->attemptOnAllConnections(__FUNCTION__, func_get_args(), $job);
    }

    /**
     * Push a raw payload onto the queue.
     */
    public function pushRaw(string $payload, ?string $queue = null, array $options = []): mixed
    {
        return $this->attemptOnAllConnections(__FUNCTION__, func_get_args());
    }

    /**
     * Push a new job onto the queue after (n) seconds.
     */
    public function later(DateInterval|DateTimeInterface|int $delay, object|string $job, mixed $data = '', ?string $queue = null): mixed
    {
        return $this->attemptOnAllConnections(__FUNCTION__, func_get_args(), $job);
    }

    /**
     * Pop the next job off of the queue.
     */
    public function pop(?string $queue = null, int $index = 0): ?JobContract
    {
        $connection = $this->manager->connection($this->connections[0]);

        return $connection instanceof IndexAwareQueue
            ? $connection->pop($queue, $index)
            : $connection->pop($queue);
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

        $contextKey = self::FAILING_QUEUES_CONTEXT_PREFIX . spl_object_id($this);
        $failingQueues = CoroutineContext::get($contextKey, []);

        [$lastException, $failedQueues] = [null, []];

        try {
            foreach ($this->connections as $connection) {
                try {
                    return $this->manager->connection($connection)->{$method}(...$arguments);
                } catch (CanceledException $exception) {
                    throw $exception;
                } catch (Throwable $e) {
                    $lastException = $e;

                    $failedQueues[] = $connection;

                    if ($job !== null
                        && ! in_array($connection, $failingQueues, true)
                        && $this->events->hasListeners(QueueFailedOver::class)
                    ) {
                        $this->events->dispatch(new QueueFailedOver($connection, $job, $e));
                    }
                }
            }
        } finally {
            CoroutineContext::set($contextKey, $failedQueues);
        }

        throw $lastException ?? new RuntimeException('All failover queue connections failed.');
    }

    /**
     * Defer the failover attempt until every applicable transaction commits.
     */
    protected function deferUntilAllTransactionsCommit(
        DatabaseTransactionsManager $transactions,
        string $method,
        array $arguments,
        object|string $job
    ): bool {
        $connections = $transactions->callbackApplicableTransactions()
            ->map(static fn (DatabaseTransactionRecord $transaction): string => $transaction->connection)
            ->uniqueStrict()
            ->values()
            ->all();

        if ($connections === []) {
            return false;
        }

        $pendingConnections = array_fill_keys($connections, true);
        $settled = false;
        $releaseLocks = $this->createJobRollbackCallback($job);

        // Cancellation must be ready before addCallback(), which may execute inline.
        foreach ($connections as $connection) {
            $transactions->addCallbackForRollback(
                static function () use (&$settled, $releaseLocks): void {
                    if ($settled) {
                        return;
                    }

                    $settled = true;
                    $releaseLocks?->__invoke();
                },
                $connection
            );
        }

        foreach ($connections as $connection) {
            $transactions->addCallback(
                function () use (&$pendingConnections, &$settled, $connection, $method, $arguments, $job): void {
                    if ($settled) {
                        return;
                    }

                    unset($pendingConnections[$connection]);

                    if ($pendingConnections !== []) {
                        return;
                    }

                    $settled = true;

                    if (is_object($job)) {
                        DispatchLockContext::claim($job);
                    }

                    try {
                        $this->attemptOnAllConnections($method, $arguments, $job);
                    } finally {
                        if (is_object($job)) {
                            DispatchLockContext::release($job);
                        }
                    }
                },
                $connection
            );
        }

        if (is_object($job)) {
            DispatchLockContext::delegate($job);
        }

        return true;
    }
}
