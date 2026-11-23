<?php

declare(strict_types=1);

namespace QueueJobDependency\Jobs;

use Hypervel\Bus\Batchable;
use Hypervel\Bus\Queueable;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Foundation\Bus\Dispatchable;

abstract class BatchJob implements ShouldQueue
{
    use Batchable;
    use Dispatchable;
    use Queueable;

    public function handle(): void
    {
    }
}
