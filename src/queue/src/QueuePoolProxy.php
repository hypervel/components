<?php

declare(strict_types=1);

namespace Hypervel\Queue;

use DateInterval;
use DateTimeInterface;
use Hypervel\Contracts\Queue\Job;
use Hypervel\Contracts\Queue\Queue;
use Hypervel\ObjectPool\PoolErrorReporter;
use Hypervel\ObjectPool\PoolProxy;
use Hypervel\Queue\Jobs\Job as PoolLeaseAwareJob;
use RuntimeException;
use Throwable;

class QueuePoolProxy extends PoolProxy implements Queue
{
    /**
     * The logical connection name applied to each borrowed queue.
     */
    protected string $connectionName = '';

    /**
     * Get the size of the queue.
     */
    public function size(?string $queue = null): int
    {
        return $this->invoke(__FUNCTION__, func_get_args());
    }

    /**
     * Get the current queue workload for the application.
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
     * Pop the next job off of the queue.
     */
    public function pop(?string $queue = null): ?Job
    {
        $lease = $this->lease();

        try {
            /** @var Queue $connection */
            $connection = $lease->get();
            $job = $connection->pop($queue);

            if ($job === null) {
                $lease->release();

                return null;
            }

            if (! $job instanceof PoolLeaseAwareJob) {
                try {
                    $job->release(0);
                } catch (Throwable $requeueException) {
                    try {
                        $lease->discard();
                    } catch (Throwable $cleanupException) {
                        PoolErrorReporter::report($cleanupException);
                    }

                    throw new RuntimeException(
                        'Pooled queue connections require jobs extending Hypervel\Queue\Jobs\Job; '
                        . 'requeueing the popped job also failed.',
                        previous: $requeueException,
                    );
                }

                throw new RuntimeException(
                    'Pooled queue connections require jobs extending Hypervel\Queue\Jobs\Job.'
                );
            }

            try {
                return $job->withPoolLease($lease);
            } catch (Throwable $attachmentException) {
                try {
                    $job->release(0);
                } catch (Throwable $recoveryException) {
                    PoolErrorReporter::report($recoveryException);

                    try {
                        // Terminal recovery normally finalizes the attached lease;
                        // this is an idempotent backstop if attachment or finalization failed first.
                        $lease->discard();
                    } catch (Throwable $cleanupException) {
                        PoolErrorReporter::report($cleanupException);
                    }
                }

                throw $attachmentException;
            }
        } catch (Throwable $operationException) {
            try {
                $lease->release();
            } catch (Throwable $finalizationException) {
                PoolErrorReporter::report($finalizationException);
            }

            throw $operationException;
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
    }
}
