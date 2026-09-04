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
    public const string LAST_PUSHED_CONTEXT_KEY = '__horizon.queue.last_pushed';

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

        try {
            $payload = (new JobPayload($payload))->prepare($job);

            if ($this->hasEventListeners(JobPending::class)) {
                $this->event($this->getQueue($queue), new JobPending($payload->value));
            }

            parent::pushRaw($payload->value, $queue, $options);

            if ($this->hasEventListeners(JobPushed::class)) {
                $this->event($this->getQueue($queue), new JobPushed($payload->value));
            }

            return $payload->id();
        } finally {
            CoroutineContext::forget(static::LAST_PUSHED_CONTEXT_KEY);
        }
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
        return $this->enqueueUsing(
            $job,
            $this->createPayload($job, $this->getQueue($queue), $data, $delay),
            $queue,
            $delay,
            static function (BaseQueue $owner, string $payload, ?string $queue, DateInterval|DateTimeInterface|int $delay) use ($job) {
                // Horizon's Redis queue is not pooled, so after-commit dispatch returns this same queue type.
                /** @var self $owner */
                $payload = (new JobPayload($payload))->prepare($job)->value;

                if ($owner->hasEventListeners(JobPending::class)) {
                    $owner->event($owner->getQueue($queue), new JobPending($payload));
                }

                $id = $owner->laterRaw($delay, $payload, $queue);

                if ($owner->hasEventListeners(JobPushed::class)) {
                    $owner->event($owner->getQueue($queue), new JobPushed($payload));
                }

                return $id;
            }
        );
    }

    /**
     * Prepare a payload for bulk storage.
     */
    #[Override]
    protected function preparePayloadForBulk(object|string $job, string $payload, ?string $queue): string
    {
        $payload = (new JobPayload($payload))->prepare($job)->value;

        if ($this->hasEventListeners(JobPending::class)) {
            $this->event($this->getQueue($queue), new JobPending($payload));
        }

        return $payload;
    }

    /**
     * Handle a payload that was stored as part of a batch.
     */
    #[Override]
    protected function handlePayloadPushedInBulk(string $payload, ?string $queue): void
    {
        if ($this->hasEventListeners(JobPushed::class)) {
            $this->event($this->getQueue($queue), new JobPushed($payload));
        }
    }

    /**
     * Pop the next job off of the queue.
     */
    #[Override]
    public function pop(?string $queue = null, int $index = 0): ?Job
    {
        return tap(parent::pop($queue, $index), function ($result) use ($queue) {
            /** @var null|RedisJob $result */
            if ($result && $this->hasEventListeners(JobReserved::class)) {
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
            if ($this->hasEventListeners(JobsMigrated::class)) {
                $this->event($to, new JobsMigrated($jobs));
            }
        });
    }

    /**
     * Delete a reserved job from the queue.
     */
    #[Override]
    public function deleteReserved(string $queue, RedisJob $job): void
    {
        parent::deleteReserved($queue, $job);

        if (! $this->hasEventListeners(JobDeleted::class)) {
            return;
        }

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

        if (! $this->hasEventListeners(JobReleased::class)) {
            return;
        }

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
     * Determine if the given Horizon event has listeners.
     */
    protected function hasEventListeners(string $event): bool
    {
        return $this->container->bound(Dispatcher::class)
            && $this->container->make(Dispatcher::class)->hasListeners($event);
    }

    /**
     * Set the job that last pushed to queue via the "push" method.
     */
    protected function setLastPushed(object|string $job): void
    {
        CoroutineContext::set(static::LAST_PUSHED_CONTEXT_KEY, $job);
    }
}
