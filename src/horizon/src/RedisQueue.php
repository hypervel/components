<?php

declare(strict_types=1);

namespace Hypervel\Horizon;

use DateInterval;
use DateTimeInterface;
use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Queue\Job;
use Hypervel\Horizon\Events\JobDeleted;
use Hypervel\Horizon\Events\JobPending;
use Hypervel\Horizon\Events\JobPushed;
use Hypervel\Horizon\Events\JobReleased;
use Hypervel\Horizon\Events\JobReserved;
use Hypervel\Horizon\Events\JobsMigrated;
use Hypervel\Queue\InvalidPayloadException;
use Hypervel\Queue\Jobs\RedisJob;
use Hypervel\Queue\RedisQueue as BaseQueue;
use Hypervel\Support\Str;
use Override;

class RedisQueue extends BaseQueue
{
    public const LAST_PUSHED_CONTEXT_KEY = '__horizon.queue.last_pushed';

    /**
     * Get the number of queue jobs that are ready to process.
     */
    public function readyNow(?string $queue = null): int
    {
        return $this->getConnection()->lLen($this->getQueueRedisKey($queue));
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
            static function (BaseQueue $owner, $payload, $queue) use ($job) {
                /** @var self $owner */
                $owner->setLastPushed($job);

                return $owner->pushRaw($payload, $queue);
            }
        );
    }

    /**
     * Push a raw payload onto the queue.
     */
    #[Override]
    public function pushRaw(string $payload, ?string $queue = null, array $options = []): mixed
    {
        $job = CoroutineContext::get(static::LAST_PUSHED_CONTEXT_KEY);
        CoroutineContext::forget(static::LAST_PUSHED_CONTEXT_KEY);

        $payload = (new JobPayload($payload))->prepare($job);

        $this->event($this->getQueue($queue), new JobPending($payload->value));

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
        $payload = (new JobPayload(
            $this->createPayload($job, $this->getQueue($queue), $data, $delay)
        ))->prepare($job)->value;

        return $this->enqueueUsing(
            $job,
            $payload,
            $queue,
            $delay,
            function (BaseQueue $owner, $payload, $queue, $delay) {
                // The base callback supplies the unused owner first. This callback must remain
                // bound for parent::laterRaw(); Horizon's Redis queue is not pooled.
                $this->event($this->getQueue($queue), new JobPending($payload));

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
            /** @var null|RedisJob $result */
            if ($result) {
                try {
                    $event = new JobReserved($result->getReservedJob());
                } catch (InvalidPayloadException) {
                    return;
                }

                $this->event($this->getQueue($queue), $event);
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
            $this->event($to, new JobsMigrated($jobs));
        });
    }

    /**
     * Delete a reserved job from the queue.
     */
    #[Override]
    public function deleteReserved(string $queue, RedisJob $job): void
    {
        parent::deleteReserved($queue, $job);

        try {
            $event = new JobDeleted($job, $job->getReservedJob());
        } catch (InvalidPayloadException) {
            return;
        }

        $this->event($this->getQueue($queue), $event);
    }

    /**
     * Delete a reserved job from the reserved queue and release it.
     */
    #[Override]
    public function deleteAndRelease(string $queue, RedisJob $job, DateInterval|DateTimeInterface|int $delay): void
    {
        parent::deleteAndRelease($queue, $job, $delay);

        try {
            $event = new JobReleased($job->getReservedJob(), $delay);
        } catch (InvalidPayloadException) {
            return;
        }

        $this->event($this->getQueue($queue), $event);
    }

    /**
     * Fire the given event if a dispatcher is bound.
     */
    protected function event(string $queue, mixed $event): void
    {
        if ($this->container->bound(Dispatcher::class)) {
            $queue = Str::replaceFirst('queues:', '', $queue);

            $this->container->make(Dispatcher::class)->dispatch(
                $event->connection($this->getConnectionName())->queue($queue)
            );
        }
    }

    /**
     * Set the job that last pushed to queue via the "push" method.
     */
    protected function setLastPushed(object|string $job): void
    {
        CoroutineContext::set(static::LAST_PUSHED_CONTEXT_KEY, $job);
    }
}
