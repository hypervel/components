<?php

declare(strict_types=1);

namespace Hypervel\Queue\Events;

use Closure;
use JsonException;
use Throwable;

class JobQueueingFailed
{
    /**
     * Create a new event instance.
     *
     * @param Closure|object|string $job
     * @param null|int $delay the number of seconds the job was delayed
     */
    public function __construct(
        public string $connectionName,
        public ?string $queue,
        public object|string $job,
        public string $payload,
        public ?int $delay,
        public Throwable $exception
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
