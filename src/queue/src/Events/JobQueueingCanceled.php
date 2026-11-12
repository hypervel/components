<?php

declare(strict_types=1);

namespace Hypervel\Queue\Events;

use Closure;
use JsonException;
use Throwable;

/**
 * Dispatched when coroutine cancellation stops a job's queueing, or interrupts
 * the notification of its outcome.
 */
class JobQueueingCanceled
{
    /**
     * Create a new event instance.
     *
     * @param Closure|object|string $job
     * @param null|int $delay the number of seconds the job was delayed
     * @param Throwable $cancellation the cancellation, or a failure carrying it
     * @param bool $sendAttempted whether the job was handed to storage or the broker
     * @param bool $queued whether the job was stored, when cancellation interrupted its queued notification
     * @param null|Throwable $exception the queueing failure, when cancellation interrupted its failure notification
     */
    public function __construct(
        public string $connectionName,
        public ?string $queue,
        public object|string $job,
        public string $payload,
        public ?int $delay,
        public Throwable $cancellation,
        public bool $sendAttempted,
        public bool $queued = false,
        public ?Throwable $exception = null,
    ) {
    }

    /**
     * Get the decoded job payload.
     *
     * @throws JsonException
     */
    public function payload(): array
    {
        return json_decode($this->payload, true, flags: JSON_THROW_ON_ERROR);
    }
}
