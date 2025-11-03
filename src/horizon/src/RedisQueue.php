<?php

declare(strict_types=1);

namespace Hypervel\Horizon;

use DateInterval;
use DateTimeInterface;
use Hypervel\Context\Context;
use Hypervel\Event\Contracts\Dispatcher;
use Hypervel\Horizon\Events\JobDeleted;
use Hypervel\Horizon\Events\JobPushed;
use Hypervel\Horizon\Events\JobReleased;
use Hypervel\Horizon\Events\JobReserved;
use Hypervel\Horizon\Events\JobsMigrated;
use Hypervel\Queue\Jobs\Job;
use Hypervel\Queue\Jobs\RedisJob;
use Hypervel\Queue\RedisQueue as BaseQueue;
use Hypervel\Support\Str;
use Override;

class RedisQueue extends BaseQueue
{
    public const LAST_PUSHED_CONTEXT_KEY = 'horizon.queue.last_pushed';

    /**
     * Get the number of queue jobs that are ready to process.
     */
    public function readyNow(?string $queue = null): int
    {
        return $this->getConnection()->lLen($this->getQueue($queue));
    }

    /**
     * Push a new job onto the queue.
     */
    #[Override]
    public function push(object|string $job, mixed $data = '', ?string $queue = null): mixed
    {
        return $this->enqueueUsing(
            $job,
            $this->createPayload($job, $this->getQueue($queue), $data),
            $queue,
            null,
            function ($payload, $queue) use ($job) {
                $this->setLastPushed($job);

                return $this->pushRaw($payload, $queue);
            }
        );
    }

    /**
     * Push a raw payload onto the queue.
     */
    #[Override]
    public function pushRaw(string $payload, ?string $queue = null, array $options = []): mixed
    {
        $payload = (new JobPayload($payload))->prepare($this->getLastPushed());

        parent::pushRaw($payload->value, $queue, $options);

        $this->event($this->getQueue($queue), new JobPushed($payload->value));

        return $payload->id();
    }

    /**
     * Create a payload string from the given job and data.
     */
    #[Override]
    protected function createPayloadArray(array|object|string $job, ?string $queue, mixed $data = ''): array
    {
        $payload = parent::createPayloadArray($job, $queue, $data);

        $payload['id'] = $payload['uuid'];

        return $payload;
    }

    /**
     * Push a new job onto the queue after a delay.
     */
    #[Override]
    public function later(DateInterval|DateTimeInterface|int $delay, object|string $job, mixed $data = '', ?string $queue = null): mixed
    {
        $payload = (new JobPayload($this->createPayload($job, $queue, $data)))->prepare($job)->value;

        return $this->enqueueUsing(
            $job,
            $payload,
            $queue,
            $delay,
            function ($payload, $queue, $delay) {
                return tap(parent::laterRaw($delay, $payload, $queue), function () use ($payload, $queue) {
                    $this->event($this->getQueue($queue), new JobPushed($payload));
                });
            }
        );
    }

    /**
     * Pop the next job off of the queue.
     */
    #[Override]
    public function pop(?string $queue = null, int $index = 0): ?Job
    {
        return tap(parent::pop($queue, $index), function ($result) use ($queue) {
            if ($result) {
                $this->event($this->getQueue($queue), new JobReserved($result->getReservedJob()));
            }
        });
    }

    /**
     * Migrate the delayed jobs that are ready to the regular queue.
     */
    #[Override]
    public function migrateExpiredJobs(string $from, string $to): array
    {
        return tap(parent::migrateExpiredJobs($from, $to), function ($jobs) use ($to) {
            $this->event($to, new JobsMigrated($jobs === false ? [] : $jobs));
        });
    }

    /**
     * Delete a reserved job from the queue.
     */
    #[Override]
    public function deleteReserved(string $queue, RedisJob $job): void
    {
        parent::deleteReserved($queue, $job);

        $this->event($this->getQueue($queue), new JobDeleted($job, $job->getReservedJob()));
    }

    /**
     * Delete a reserved job from the reserved queue and release it.
     */
    #[Override]
    public function deleteAndRelease(string $queue, RedisJob $job, DateInterval|DateTimeInterface|int $delay): void
    {
        parent::deleteAndRelease($queue, $job, $delay);

        $this->event($this->getQueue($queue), new JobReleased($job->getReservedJob()));
    }

    /**
     * Fire the given event if a dispatcher is bound.
     */
    protected function event(string $queue, mixed $event): void
    {
        if ($this->container && $this->container->has(Dispatcher::class)) {
            $queue = Str::replaceFirst('queues:', '', $queue);

            $this->container->get(Dispatcher::class)->dispatch(
                $event->connection($this->getConnectionName())->queue($queue)
            );
        }
    }

    /**
     * Set the job that last pushed to queue via the "push" method.
     */
    protected function setLastPushed(object|string $job): void
    {
        Context::set(static::LAST_PUSHED_CONTEXT_KEY, $job);
    }

    /**
     * Get the job that last pushed to queue via the "push" method.
     */
    protected function getLastPushed(): object|string|null
    {
        return Context::get(static::LAST_PUSHED_CONTEXT_KEY);
    }
}
