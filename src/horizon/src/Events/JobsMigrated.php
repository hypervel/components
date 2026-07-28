<?php

declare(strict_types=1);

namespace Hypervel\Horizon\Events;

use Hypervel\Horizon\JobPayload;
use Hypervel\Queue\InvalidPayloadException;
use Hypervel\Support\Collection;

class JobsMigrated
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
     * The job payloads that were migrated.
     */
    public Collection $payloads;

    /**
     * Create a new event instance.
     */
    public function __construct(array $payloads)
    {
        $this->payloads = collect($payloads)->map(function ($job) {
            try {
                return new JobPayload($job);
            } catch (InvalidPayloadException) {
                return null;
            }
        })->filter()->values();
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
