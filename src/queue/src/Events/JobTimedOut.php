<?php

declare(strict_types=1);

namespace Hypervel\Queue\Events;

use Hypervel\Contracts\Queue\Job;

class JobTimedOut
{
    /**
     * Create a new event instance.
     *
     * @param null|int $timeout the timeout exceeded in seconds
     */
    public function __construct(
        public string $connectionName,
        public Job $job,
        public ?int $timeout = null,
    ) {
    }
}
