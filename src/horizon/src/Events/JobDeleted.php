<?php

declare(strict_types=1);

namespace Hypervel\Horizon\Events;

use Hypervel\Queue\Jobs\RedisJob;

class JobDeleted extends RedisEvent
{
    /**
     * The queue job instance.
     */
    public RedisJob $job;

    /**
     * Create a new event instance.
     */
    public function __construct(RedisJob $job, string $payload)
    {
        $this->job = $job;

        parent::__construct($payload);
    }
}
