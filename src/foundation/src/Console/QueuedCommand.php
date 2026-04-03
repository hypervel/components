<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Console;

use Hypervel\Bus\Queueable;
use Hypervel\Contracts\Console\Kernel as KernelContract;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Foundation\Bus\Dispatchable;

class QueuedCommand implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(
        protected array $data
    ) {
    }

    /**
     * Handle the job.
     */
    public function handle(KernelContract $kernel): void
    {
        $kernel->call(...array_values($this->data));
    }

    /**
     * Get the display name for the queued job.
     */
    public function displayName(): string
    {
        return array_values($this->data)[0];
    }
}
