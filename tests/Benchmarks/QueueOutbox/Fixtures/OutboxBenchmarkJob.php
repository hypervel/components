<?php

declare(strict_types=1);

namespace Hypervel\Tests\Benchmarks\QueueOutbox\Fixtures;

use Hypervel\Bus\Queueable;
use Hypervel\Contracts\Queue\ShouldQueue;

class OutboxBenchmarkJob implements ShouldQueue
{
    use Queueable;

    /**
     * Create the same ordinary job for baseline and outbox workloads.
     */
    public function __construct(public string $contents)
    {
    }

    /**
     * Leave application processing outside the publication benchmark.
     */
    public function handle(): void
    {
    }
}
