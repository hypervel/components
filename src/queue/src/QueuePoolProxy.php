<?php

declare(strict_types=1);

namespace Hypervel\Queue;

use Closure;
use DateInterval;
use DateTimeInterface;
use Hypervel\Contracts\Queue\IndexAwareQueue;
use Hypervel\Contracts\Queue\Job;
use Hypervel\Contracts\Queue\Queue as QueueContract;
use Hypervel\ObjectPool\Contracts\Factory;
use Hypervel\ObjectPool\PoolDefinition;
use Hypervel\ObjectPool\PoolErrorReporter;
use Hypervel\ObjectPool\PoolProxy;
use Hypervel\Queue\Jobs\Job as PoolLeaseAwareJob;
use Hypervel\Support\Collection;
use RuntimeException;
use Swoole\Coroutine\CanceledException;
use Throwable;

class QueuePoolProxy extends PoolProxy implements QueueContract, IndexAwareQueue
{
    /**
     * The logical connection name applied to each borrowed queue.
     */
    protected string $connectionName = '';

    /** @var Closure(Closure(Queue): mixed): mixed */
    protected Closure $afterCommitDispatcher;

    /**
     * Create a pooled queue proxy.
     */
    public function __construct(
        PoolDefinition $definition,
        Closure $resolver,
        Factory $pools,
        ?Closure $releaseCallback = null,
    ) {
        $this->afterCommitDispatcher = fn (Closure $callback) => $this->usingConnection($callback);

        parent::__construct(
            $definition,
            $resolver,
            $pools,
            static function (Queue $queue) use ($releaseCallback): void {
                $queue->setAfterCommitDispatcher(null);
                $releaseCallback?->__invoke($queue);
            },
        );
    }

    /**
     * Get the size of the queue.
     */
    public function size(?string $queue = null): int
    {
        return $this->invoke(__FUNCTION__, func_get_args());
    }

    /**
     * Get the number of pending jobs.
     */
    public function pendingSize(?string $queue = null): int
    {
        return $this->invoke(__FUNCTION__, func_get_args());
    }

    /**
     * Get the number of delayed jobs.
     */
    public function delayedSize(?string $queue = null): int
    {
        return $this->invoke(__FUNCTION__, func_get_args());
    }

    /**
     * Get the number of reserved jobs.
     */
    public function reservedSize(?string $queue = null): int
    {
        return $this->invoke(__FUNCTION__, func_get_args());
    }

    /**
     * Get the number of jobs across every queue.
     */
    public function totalSize(): int
    {
        return $this->invoke(__FUNCTION__, func_get_args());
    }

    /**
     * Get the number of pending jobs across every queue.
     */
    public function totalPendingSize(): int
    {
        return $this->invoke(__FUNCTION__, func_get_args());
    }

    /**
     * Get the number of delayed jobs across every queue.
     */
    public function totalDelayedSize(): int
    {
        return $this->invoke(__FUNCTION__, func_get_args());
    }

    /**
     * Get the number of reserved jobs across every queue.
     */
    public function totalReservedSize(): int
    {
        return $this->invoke(__FUNCTION__, func_get_args());
    }

    /**
     * Get the pending jobs for the given queue.
     */
    public function pendingJobs(?string $queue = null): Collection
    {
        return $this->invoke(__FUNCTION__, func_get_args());
    }

    /**
     * Get the delayed jobs for the given queue.
     */
    public function delayedJobs(?string $queue = null): Collection
    {
        return $this->invoke(__FUNCTION__, func_get_args());
    }

    /**
     * Get the reserved jobs for the given queue.
     */
    public function reservedJobs(?string $queue = null): Collection
    {
        return $this->invoke(__FUNCTION__, func_get_args());
    }

    /**
     * Get all pending jobs across every queue.
     */
    public function allPendingJobs(): Collection
    {
        return $this->invoke(__FUNCTION__, func_get_args());
    }

    /**
     * Get all delayed jobs across every queue.
     */
    public function allDelayedJobs(): Collection
    {
        return $this->invoke(__FUNCTION__, func_get_args());
    }

    /**
     * Get all reserved jobs across every queue.
     */
    public function allReservedJobs(): Collection
    {
        return $this->invoke(__FUNCTION__, func_get_args());
    }

    /**
     * Get the creation timestamp of the oldest pending job, excluding delayed jobs.
     */
    public function creationTimeOfOldestPendingJob(?string $queue = null): ?int
    {
        return $this->invoke(__FUNCTION__, func_get_args());
    }

    /**
     * Push a new job onto the queue.
     */
    public function push(object|string $job, mixed $data = '', ?string $queue = null): mixed
    {
        return $this->invoke(__FUNCTION__, func_get_args());
    }

    /**
     * Push a new job onto the queue.
     */
    public function pushOn(?string $queue, object|string $job, mixed $data = ''): mixed
    {
        return $this->invoke(__FUNCTION__, func_get_args());
    }

    /**
     * Push a raw payload onto the queue.
     */
    public function pushRaw(string $payload, ?string $queue = null, array $options = []): mixed
    {
        return $this->invoke(__FUNCTION__, func_get_args());
    }

    /**
     * Push a new job onto the queue after (n) seconds.
     */
    public function later(DateInterval|DateTimeInterface|int $delay, object|string $job, mixed $data = '', ?string $queue = null): mixed
    {
        return $this->invoke(__FUNCTION__, func_get_args());
    }

    /**
     * Push a new job onto a specific queue after (n) seconds.
     */
    public function laterOn(?string $queue, DateInterval|DateTimeInterface|int $delay, object|string $job, mixed $data = ''): mixed
    {
        return $this->invoke(__FUNCTION__, func_get_args());
    }

    /**
     * Push an array of jobs onto the queue.
     */
    public function bulk(array $jobs, mixed $data = '', ?string $queue = null): mixed
    {
        return $this->invoke(__FUNCTION__, func_get_args());
    }

    /**
     * Run a callback with the underlying SQS queue.
     *
     * @param Closure(SqsQueue): mixed $callback
     */
    public function withConnection(Closure $callback): mixed
    {
        if ($this->definition->resourceType !== 'sqs') {
            throw new RuntimeException('Direct queue connection access is only supported for SQS queues.');
        }

        return $this->usingConnection(static function (Queue $queue) use ($callback) {
            /** @var SqsQueue $queue */
            return $callback($queue);
        });
    }

    /**
     * Pop the next job off of the queue.
     */
    public function pop(?string $queue = null, int $index = 0): ?Job
    {
        $lease = $this->lease();

        try {
            /** @var QueueContract $connection */
            $connection = $lease->get();
            $job = $connection instanceof IndexAwareQueue
                ? $connection->pop($queue, $index)
                : $connection->pop($queue);

            if ($job === null) {
                $lease->release();

                return null;
            }

            if (! $job instanceof PoolLeaseAwareJob) {
                try {
                    $job->release(0);
                } catch (CanceledException $requeueCancellation) {
                    $lease->discardAfterFailure($requeueCancellation);
                } catch (Throwable $requeueException) {
                    $lease->discardAfterFailure(new RuntimeException(
                        'Pooled queue connections require jobs extending Hypervel\Queue\Jobs\Job; '
                        . 'requeueing the popped job also failed.',
                        previous: $requeueException,
                    ));
                }

                throw new RuntimeException(
                    'Pooled queue connections require jobs extending Hypervel\Queue\Jobs\Job.'
                );
            }

            try {
                return $job->withPoolLease($lease);
            } catch (CanceledException $attachmentCancellation) {
                $lease->discardAfterFailure($attachmentCancellation);
            } catch (Throwable $attachmentException) {
                try {
                    $job->release(0);
                } catch (CanceledException $recoveryCancellation) {
                    $lease->discardAfterFailure($recoveryCancellation);
                } catch (Throwable $recoveryException) {
                    PoolErrorReporter::report($recoveryException);

                    $lease->discardAfterFailure($attachmentException);
                }

                throw $attachmentException;
            }
        } catch (Throwable $operationException) {
            // Inner recovery can finalize this lease before throwing here.
            $lease->releaseAfterFailure($operationException);
        }
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
     */
    public function setConnectionName(string $name): static
    {
        $this->connectionName = $name;

        return $this;
    }

    /**
     * Apply the proxy's logical connection name to a borrowed queue.
     */
    protected function configureBorrowed(object $object): void
    {
        /** @var Queue $object */
        $object->setConnectionName($this->connectionName);
        $object->setAfterCommitDispatcher($this->afterCommitDispatcher);
    }

    /**
     * Run a callback with a borrowed queue.
     *
     * @param Closure(Queue): mixed $callback
     */
    protected function usingConnection(Closure $callback): mixed
    {
        $lease = $this->lease();

        try {
            /** @var Queue $queue */
            $queue = $lease->get();
            $result = $callback($queue);
        } catch (Throwable $operationException) {
            $lease->releaseAfterFailure($operationException);
        }

        $lease->release();

        return $result;
    }
}
