<?php

declare(strict_types=1);

namespace Hypervel\Queue\Jobs;

use Hypervel\Contracts\Container\Container;
use Pheanstalk\Contract\JobIdInterface;
use Pheanstalk\Contract\PheanstalkManagerInterface;
use Pheanstalk\Pheanstalk;
use RuntimeException;
use Throwable;

/**
 * Keep the reserving Pheanstalk connection pinned until the backend's terminal
 * release, bury, or delete command has completed.
 */
class BeanstalkdJob extends Job
{
    /**
     * The last attempt count read while the backend connection was pinned.
     */
    protected ?int $attempts = null;

    /**
     * Create a new job instance.
     */
    public function __construct(
        protected Container $container,
        protected PheanstalkManagerInterface $pheanstalk,
        protected JobIdInterface $job,
        protected string $connectionName,
        protected string $queue
    ) {
    }

    /**
     * Release the job back into the queue after (n) seconds.
     */
    public function release(int $delay = 0): void
    {
        parent::release($delay);

        $priority = Pheanstalk::DEFAULT_PRIORITY;

        try {
            /* @phpstan-ignore-next-line */
            $this->getPheanstalk()->release($this->job, $priority, $delay);
        } catch (Throwable $exception) {
            $this->discardPoolLeaseAfterFailure($exception);
        }

        $this->releasePoolLease();
    }

    /**
     * Bury the job in the queue.
     */
    public function bury(): void
    {
        parent::release();

        try {
            /* @phpstan-ignore-next-line */
            $this->getPheanstalk()->bury($this->job);
        } catch (Throwable $exception) {
            $this->discardPoolLeaseAfterFailure($exception);
        }

        $this->releasePoolLease();
    }

    /**
     * Delete the job from the queue.
     */
    public function delete(): void
    {
        parent::delete();

        try {
            /* @phpstan-ignore-next-line */
            $this->getPheanstalk()->delete($this->job);
        } catch (Throwable $exception) {
            $this->discardPoolLeaseAfterFailure($exception);
        }

        $this->releasePoolLease();
    }

    /**
     * Get the number of times the job has been attempted.
     */
    public function attempts(): int
    {
        if ($this->poolLeaseIsFinalized()) {
            if ($this->attempts === null) {
                throw new RuntimeException('The pooled Beanstalkd job attempt count was not initialized before its backend lease was finalized.');
            }

            return $this->attempts;
        }

        /* @phpstan-ignore-next-line */
        $stats = $this->getPheanstalk()->statsJob($this->job);

        return $this->attempts = (int) $stats->reserves;
    }

    /**
     * Get the job identifier.
     */
    public function getJobId(): string
    {
        return $this->job->getId();
    }

    /**
     * Get the raw body string for the job.
     */
    public function getRawBody(): string
    {
        /* @phpstan-ignore-next-line */
        return $this->job->getData();
    }

    /**
     * Get the underlying Pheanstalk instance.
     */
    public function getPheanstalk(): PheanstalkManagerInterface
    {
        if ($this->poolLeaseIsFinalized()) {
            throw new RuntimeException('The pooled Beanstalkd job backend is no longer available after a terminal operation.');
        }

        return $this->pheanstalk;
    }

    /**
     * Get the underlying Pheanstalk job.
     */
    public function getPheanstalkJob(): JobIdInterface
    {
        return $this->job;
    }

    /**
     * Prime state needed after the pooled backend lease is finalized.
     */
    protected function onPoolLeaseAttached(): void
    {
        $this->attempts();
    }
}
