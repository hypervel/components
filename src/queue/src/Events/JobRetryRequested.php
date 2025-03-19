<?php

declare(strict_types=1);

namespace Hypervel\Queue\Events;

use stdClass;

class JobRetryRequested
{
    /**
     * The decoded job payload.
     */
    protected ?array $payload = null;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public stdClass $job
    ) {
        $this->job = $job;
    }

    /**
     * The job payload.
     */
    public function payload(): array
    {
        if (is_null($this->payload)) {
            $this->payload = json_decode($this->job->payload, true);
        }

        return $this->payload;
    }
}
