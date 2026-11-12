<?php

declare(strict_types=1);

namespace QueueJobDependency\Jobs;

use Hypervel\Bus\Queueable;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Foundation\Bus\Dispatchable;

class ConstructedJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public function __construct()
    {
    }
}
