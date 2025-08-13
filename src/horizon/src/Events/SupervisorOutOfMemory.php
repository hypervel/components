<?php

declare(strict_types=1);

namespace Hypervel\Horizon\Events;

use Hypervel\Horizon\Supervisor;

class SupervisorOutOfMemory
{
    /**
     * The memory usage that exceeded the allowable limit.
     */
    public float|int $memoryUsage;

    /**
     * Create a new event instance.
     *
     * @param Supervisor $supervisor the supervisor instance
     */
    public function __construct(
        public Supervisor $supervisor
    ) {
    }

    /**
     * Get the memory usage that triggered the event.
     */
    public function getMemoryUsage(): float|int
    {
        return $this->memoryUsage ?? $this->supervisor->memoryUsage();
    }

    /**
     * Set the memory usage that was recorded when the event was dispatched.
     */
    public function setMemoryUsage(float|int $memoryUsage): static
    {
        $this->memoryUsage = $memoryUsage;

        return $this;
    }
}
