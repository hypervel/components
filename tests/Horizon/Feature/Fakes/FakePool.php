<?php

declare(strict_types=1);

namespace Hypervel\Tests\Horizon\Feature\Fakes;

use Hypervel\Horizon\ProcessPool;

class FakePool extends ProcessPool
{
    public $queue;

    public $processCount;

    public function __construct($queue, $processCount)
    {
        $this->queue = $queue;
        $this->processCount = $processCount;
    }

    public function scale($processCount): void
    {
        $this->processCount = $processCount;
    }

    public function queue(): string
    {
        return $this->queue;
    }

    public function pruneTerminatingProcesses(): void
    {
    }

    public function totalProcessCount(): int
    {
        return (int) $this->processCount;
    }
}
