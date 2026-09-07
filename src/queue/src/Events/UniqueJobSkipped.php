<?php

declare(strict_types=1);

namespace Hypervel\Queue\Events;

class UniqueJobSkipped
{
    /**
     * Create a new event instance.
     *
     * @param object $job the job that was not dispatched
     */
    public function __construct(
        public object $job,
    ) {
    }
}
