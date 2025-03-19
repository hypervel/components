<?php

declare(strict_types=1);

namespace Hypervel\Queue\Jobs;

use Pheanstalk\Contract\JobIdInterface;
use Pheanstalk\Contract\PheanstalkManagerInterface;
use Pheanstalk\Pheanstalk;
use Psr\Container\ContainerInterface;

class BeanstalkdJob extends Job
{
    /**
     * Create a new job instance.
     */
    public function __construct(
        protected ContainerInterface $container,
        protected PheanstalkManagerInterface $pheanstalk,
        protected JobIdInterface $job,
        protected string $connectionName,
        protected ?string $queue
    ) {
    }

    /**
     * Release the job back into the queue after (n) seconds.
     */
    public function release(int $delay = 0): void
    {
        parent::release($delay);

        $priority = Pheanstalk::DEFAULT_PRIORITY;

        /* @phpstan-ignore-next-line */
        $this->pheanstalk->release($this->job, $priority, $delay);
    }

    /**
     * Bury the job in the queue.
     */
    public function bury(): void
    {
        parent::release();

        /* @phpstan-ignore-next-line */
        $this->pheanstalk->bury($this->job);
    }

    /**
     * Delete the job from the queue.
     */
    public function delete(): void
    {
        parent::delete();

        /* @phpstan-ignore-next-line */
        $this->pheanstalk->delete($this->job);
    }

    /**
     * Get the number of times the job has been attempted.
     */
    public function attempts(): int
    {
        /* @phpstan-ignore-next-line */
        $stats = $this->pheanstalk->statsJob($this->job);

        return (int) $stats->reserves;
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
        return $this->pheanstalk;
    }

    /**
     * Get the underlying Pheanstalk job.
     */
    public function getPheanstalkJob(): JobIdInterface
    {
        return $this->job;
    }
}
