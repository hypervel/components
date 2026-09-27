<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue\Fixtures;

use Hypervel\Bus\Queueable;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Foundation\Bus\Dispatchable;

class TestSyncJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    /**
     * Handle the job.
     */
    public function handle(): void
    {
    }
}
