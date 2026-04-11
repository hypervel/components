<?php

declare(strict_types=1);

namespace Hypervel\Horizon\Events;

class JobReleased extends RedisEvent
{
    /**
     * The delay in seconds before the job becomes available.
     */
    public int $delay;

    /**
     * Create a new event instance.
     */
    public function __construct(string $payload, int $delay = 0)
    {
        parent::__construct($payload);

        $this->delay = $delay;
    }
}
