<?php

declare(strict_types=1);

namespace Hypervel\Horizon\Events;

use Hypervel\Queue\Jobs\RedisJob;

class JobDeleted extends RedisEvent
{
    /**
     * Create a new event instance.
     *
     * @param RedisJob $job the queue job instance
     */
    public function __construct(
        public RedisJob $job,
        string $payload
    ) {
        parent::__construct($payload);
    }
}
