<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Horizon\Feature\Fixtures;

use Hypervel\Horizon\ProcessPool;

class FakePool extends ProcessPool
{
    public string $queue;

    public int $processCount;

    /**
     * Create a new process pool.
     */
    public function __construct(string $queue, int $processCount)
    {
        $this->queue = $queue;
        $this->processCount = $processCount;
    }

    /**
     * Set the process count.
     */
    public function scale(int $processCount): void
    {
        $this->processCount = $processCount;
    }

    /**
     * Get the queue name.
     */
    public function queue(): string
    {
        return $this->queue;
    }

    /**
     * Remove terminating processes.
     */
    public function pruneTerminatingProcesses(): void
    {
    }

    /**
     * Get the total process count.
     */
    public function totalProcessCount(): int
    {
        return $this->processCount;
    }
}
