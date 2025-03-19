<?php

declare(strict_types=1);

namespace Hypervel\Queue\Events;

use Closure;

class JobQueued
{
    /**
     * Create a new event instance.
     *
     * @param Closure|object|string $job
     */
    public function __construct(
        public string $connectionName,
        public ?string $queue,
        public null|int|string $id,
        public object|string $job,
        public string $payload,
        public ?int $delay
    ) {
    }

    /**
     * Get the decoded job payload.
     */
    public function payload(): array
    {
        return json_decode($this->payload, true, flags: JSON_THROW_ON_ERROR);
    }
}
