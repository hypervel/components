<?php

declare(strict_types=1);

namespace Hypervel\Queue;

use DateInterval;
use DateTimeInterface;
use Hypervel\Contracts\Queue\Job as JobContract;
use Hypervel\Contracts\Queue\Queue as QueueContract;
use Hypervel\Queue\Jobs\BeanstalkdJob;
use Hypervel\Support\Collection;
use Pheanstalk\Contract\JobIdInterface;
use Pheanstalk\Contract\PheanstalkManagerInterface;
use Pheanstalk\Contract\PheanstalkPublisherInterface;
use Pheanstalk\Contract\PheanstalkSubscriberInterface;
use Pheanstalk\Pheanstalk;
use Pheanstalk\Values\Job;
use Pheanstalk\Values\JobId;
use Pheanstalk\Values\TubeName;
use UnitEnum;

class BeanstalkdQueue extends Queue implements QueueContract
{
    /**
     * Create a new Beanstalkd queue instance.
     *
     * @param PheanstalkManagerInterface&PheanstalkPublisherInterface&PheanstalkSubscriberInterface $pheanstalk
     * @param string $default the name of the default tube
     * @param int $timeToRun the "time to run" for all pushed jobs
     * @param int $blockFor the maximum number of seconds to block for a job
     */
    public function __construct(
        protected PheanstalkManagerInterface $pheanstalk,
        protected string $default,
        protected int $timeToRun,
        protected int $blockFor = 0,
        bool $dispatchAfterCommit = false
    ) {
        $this->dispatchAfterCommit = $dispatchAfterCommit;
    }

    /**
     * Get the size of the queue.
     */
    public function size(UnitEnum|string|null $queue = null): int
    {
        $stats = $this->pheanstalk->statsTube(new TubeName($this->getQueue($queue)));

        return $stats->currentJobsReady
            + $stats->currentJobsDelayed
            + $stats->currentJobsReserved;
    }

    /**
     * Get the number of pending jobs.
     */
    public function pendingSize(UnitEnum|string|null $queue = null): int
    {
        return $this->pheanstalk->statsTube(new TubeName($this->getQueue($queue)))->currentJobsReady;
    }

    /**
     * Get the number of delayed jobs.
     */
    public function delayedSize(UnitEnum|string|null $queue = null): int
    {
        return $this->pheanstalk->statsTube(new TubeName($this->getQueue($queue)))->currentJobsDelayed;
    }

    /**
     * Get the number of reserved jobs.
     */
    public function reservedSize(UnitEnum|string|null $queue = null): int
    {
        return $this->pheanstalk->statsTube(new TubeName($this->getQueue($queue)))->currentJobsReserved;
    }

    /**
     * Get the number of jobs across every queue.
     */
    public function totalSize(): int
    {
        $stats = $this->pheanstalk->stats();

        return $stats->currentJobsReady
            + $stats->currentJobsDelayed
            + $stats->currentJobsReserved;
    }

    /**
     * Get the number of pending jobs across every queue.
     */
    public function totalPendingSize(): int
    {
        return $this->pheanstalk->stats()->currentJobsReady;
    }

    /**
     * Get the number of delayed jobs across every queue.
     */
    public function totalDelayedSize(): int
    {
        return $this->pheanstalk->stats()->currentJobsDelayed;
    }

    /**
     * Get the number of reserved jobs across every queue.
     */
    public function totalReservedSize(): int
    {
        return $this->pheanstalk->stats()->currentJobsReserved;
    }

    /**
     * Get the pending jobs for the given queue.
     */
    public function pendingJobs(UnitEnum|string|null $queue = null): Collection
    {
        return new Collection;
    }

    /**
     * Get the delayed jobs for the given queue.
     */
    public function delayedJobs(UnitEnum|string|null $queue = null): Collection
    {
        return new Collection;
    }

    /**
     * Get the reserved jobs for the given queue.
     */
    public function reservedJobs(UnitEnum|string|null $queue = null): Collection
    {
        return new Collection;
    }

    /**
     * Get all pending jobs across every queue.
     */
    public function allPendingJobs(): Collection
    {
        return new Collection;
    }

    /**
     * Get all delayed jobs across every queue.
     */
    public function allDelayedJobs(): Collection
    {
        return new Collection;
    }

    /**
     * Get all reserved jobs across every queue.
     */
    public function allReservedJobs(): Collection
    {
        return new Collection;
    }

    /**
     * Get the creation timestamp of the oldest pending job, excluding delayed jobs.
     */
    public function creationTimeOfOldestPendingJob(UnitEnum|string|null $queue = null): ?int
    {
        // Not supported by Beanstalkd...
        return null;
    }

    /**
     * Push a new job onto the queue.
     */
    public function push(object|string $job, mixed $data = '', UnitEnum|string|null $queue = null): mixed
    {
        $queue = $this->normalizeQueue($queue);

        return $this->enqueueUsing(
            $job,
            $this->createPayload($job, $this->getQueue($queue), $data),
            $queue,
            null,
            static function (BeanstalkdQueue $owner, string $payload, ?string $queue) {
                return $owner->pushRaw($payload, $queue);
            }
        );
    }

    /**
     * Push a raw payload onto the queue.
     */
    public function pushRaw(string $payload, UnitEnum|string|null $queue = null, array $options = []): mixed
    {
        $this->pheanstalk->useTube(new TubeName($this->getQueue($queue)));

        return $this->pheanstalk->put(
            $payload,
            Pheanstalk::DEFAULT_PRIORITY,
            Pheanstalk::DEFAULT_DELAY,
            $this->timeToRun
        );
    }

    /**
     * Push a new job onto the queue after (n) seconds.
     */
    public function later(DateInterval|DateTimeInterface|int $delay, object|string $job, mixed $data = '', UnitEnum|string|null $queue = null): mixed
    {
        $queue = $this->normalizeQueue($queue);

        return $this->enqueueUsing(
            $job,
            $this->createPayload($job, $this->getQueue($queue), $data, $delay),
            $queue,
            $delay,
            static function (
                BeanstalkdQueue $owner,
                string $payload,
                ?string $queue,
                DateInterval|DateTimeInterface|int $delay
            ) {
                $owner->pheanstalk->useTube(new TubeName($owner->getQueue($queue)));

                return $owner->pheanstalk->put(
                    $payload,
                    Pheanstalk::DEFAULT_PRIORITY,
                    $owner->secondsUntil($delay),
                    $owner->timeToRun
                );
            }
        );
    }

    /**
     * Pop the next job off of the queue.
     */
    public function pop(UnitEnum|string|null $queue = null): ?JobContract
    {
        $this->pheanstalk->watch(
            $tube = new TubeName($queue = $this->getQueue($queue))
        );

        foreach ($this->pheanstalk->listTubesWatched() as $watched) {
            if ($watched->value !== $tube->value) {
                $this->pheanstalk->ignore($watched);
            }
        }

        $job = $this->pheanstalk->reserveWithTimeout($this->blockFor);

        if ($job instanceof JobIdInterface) {
            return new BeanstalkdJob(
                $this->container,
                $this->pheanstalk,
                $job,
                $this->connectionName,
                $queue
            );
        }

        return null;
    }

    /**
     * Delete a message from the Beanstalk queue.
     */
    public function deleteMessage(UnitEnum|string $queue, int|string $id): void
    {
        $this->pheanstalk->useTube(new TubeName($this->getQueue($queue)));

        $this->pheanstalk->delete(new Job(new JobId($id), ''));
    }

    /**
     * Get the queue or return the default.
     */
    public function getQueue(UnitEnum|string|null $queue): string
    {
        $queue = $this->normalizeQueue($queue);

        return $this->resolveQueue($queue === null || $queue === '' ? $this->default : $queue);
    }

    /**
     * Get the underlying Pheanstalk instance.
     *
     * @return PheanstalkManagerInterface&PheanstalkPublisherInterface&PheanstalkSubscriberInterface
     */
    public function getPheanstalk(): PheanstalkManagerInterface
    {
        return $this->pheanstalk;
    }
}
