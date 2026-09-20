<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue\Fixtures;

use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Foundation\Queue\Queueable;
use Hypervel\Queue\Attributes\Delay;

#[Delay(15)]
class FakeSqsJobWithDelayAttribute implements ShouldQueue
{
    use Queueable;

    /**
     * Handle the queued job.
     */
    public function handle(): void
    {
    }
}
