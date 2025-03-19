<?php

declare(strict_types=1);

namespace Hypervel\Queue;

use DateInterval;
use DateTimeInterface;
use Hypervel\Queue\Contracts\Job as JobContract;
use Hypervel\Queue\Contracts\Queue as QueueContract;
use Hypervel\Queue\Jobs\BeanstalkdJob;
use Pheanstalk\Contract\JobIdInterface;
use Pheanstalk\Contract\PheanstalkManagerInterface;
use Pheanstalk\Pheanstalk;
use Pheanstalk\Values\Job;
use Pheanstalk\Values\JobId;
use Pheanstalk\Values\TubeName;

class BeanstalkdQueue extends Queue implements QueueContract
{
    /**
     * Create a new Beanstalkd queue instance.
     *
     * @param \Pheanstalk\Contract\PheanstalkManagerInterface&\Pheanstalk\Contract\PheanstalkPublisherInterface&\Pheanstalk\Contract\PheanstalkSubscriberInterface $pheanstalk
     * @param string $default the name of the default tube
     * @param int $timeToRun the "time to run" for all pushed jobs
     * @param int $blockFor the maximum number of seconds to block for a job
     */
    public function __construct(
        protected PheanstalkManagerInterface $pheanstalk,
        protected string $default,
        protected int $timeToRun,
        protected int $blockFor = 0,
        protected bool $dispatchAfterCommit = false
    ) {
        $this->default = $default;
        $this->blockFor = $blockFor;
        $this->timeToRun = $timeToRun;
        $this->pheanstalk = $pheanstalk;
        $this->dispatchAfterCommit = $dispatchAfterCommit;
    }

    /**
     * Get the size of the queue.
     */
    public function size(?string $queue = null): int
    {
        return (int) $this->pheanstalk->statsTube(new TubeName($this->getQueue($queue)))->currentJobsReady;
    }

    /**
     * Push a new job onto the queue.
     */
    public function push(object|string $job, mixed $data = '', ?string $queue = null): mixed
    {
        return $this->enqueueUsing(
            $job,
            $this->createPayload($job, $this->getQueue($queue), $data),
            $queue,
            null,
            function ($payload, $queue) {
                return $this->pushRaw($payload, $queue);
            }
        );
    }

    /**
     * Push a raw payload onto the queue.
     */
    public function pushRaw(string $payload, ?string $queue = null, array $options = []): mixed
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
    public function later(DateInterval|DateTimeInterface|int $delay, object|string $job, mixed $data = '', ?string $queue = null): mixed
    {
        return $this->enqueueUsing(
            $job,
            $this->createPayload($job, $this->getQueue($queue), $data),
            $queue,
            $delay,
            function ($payload, $queue, $delay) {
                $this->pheanstalk->useTube(new TubeName($this->getQueue($queue)));

                return $this->pheanstalk->put(
                    $payload,
                    Pheanstalk::DEFAULT_PRIORITY,
                    $this->secondsUntil($delay),
                    $this->timeToRun
                );
            }
        );
    }

    /**
     * Push an array of jobs onto the queue.
     */
    public function bulk(array $jobs, mixed $data = '', ?string $queue = null): mixed
    {
        foreach ((array) $jobs as $job) {
            if (isset($job->delay)) {
                $this->later($job->delay, $job, $data, $queue);
            } else {
                $this->push($job, $data, $queue);
            }
        }

        return null;
    }

    /**
     * Pop the next job off of the queue.
     */
    public function pop(?string $queue = null): ?JobContract
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
    public function deleteMessage(string $queue, int|string $id): void
    {
        $this->pheanstalk->useTube(new TubeName($this->getQueue($queue)));

        $this->pheanstalk->delete(new Job(new JobId($id), ''));
    }

    /**
     * Get the queue or return the default.
     */
    public function getQueue(?string $queue): string
    {
        return $queue ?: $this->default;
    }

    /**
     * Get the underlying Pheanstalk instance.
     *
     * @return \Pheanstalk\Contract\PheanstalkManagerInterface&\Pheanstalk\Contract\PheanstalkPublisherInterface&\Pheanstalk\Contract\PheanstalkSubscriberInterface
     */
    public function getPheanstalk(): PheanstalkManagerInterface
    {
        return $this->pheanstalk;
    }
}
