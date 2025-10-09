<?php

declare(strict_types=1);

namespace Hypervel\Horizon\Events;

use Hypervel\Horizon\JobPayload;

class RedisEvent
{
    /**
     * The connection name.
     */
    public string $connectionName;

    /**
     * The queue name.
     */
    public string $queue;

    /**
     * The job payload.
     */
    public JobPayload $payload;

    /**
     * Create a new event instance.
     */
    public function __construct(string $payload)
    {
        $this->payload = new JobPayload($payload);
    }

    /**
     * Set the connection name.
     */
    public function connection(string $connectionName): static
    {
        $this->connectionName = $connectionName;

        return $this;
    }

    /**
     * Set the queue name.
     */
    public function queue(string $queue): static
    {
        $this->queue = $queue;

        return $this;
    }
}
