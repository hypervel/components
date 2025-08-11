<?php

declare(strict_types=1);

namespace Hypervel\Horizon\Events;

use Hypervel\Horizon\Supervisor;

class SupervisorOutOfMemory
{
    /**
     * The supervisor instance.
     */
    public Supervisor $supervisor;

    /**
     * The memory usage that exceeded the allowable limit.
     */
    public int|float $memoryUsage;

    /**
     * Create a new event instance.
     */
    public function __construct(Supervisor $supervisor)
    {
        $this->supervisor = $supervisor;
    }

    /**
     * Get the memory usage that triggered the event.
     */
    public function getMemoryUsage(): int|float
    {
        return $this->memoryUsage ?? $this->supervisor->memoryUsage();
    }

    /**
     * Set the memory usage that was recorded when the event was dispatched.
     */
    public function setMemoryUsage(int|float $memoryUsage): static
    {
        $this->memoryUsage = $memoryUsage;

        return $this;
    }
}
