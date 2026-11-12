<?php

declare(strict_types=1);

namespace QueueJobDependency\Jobs;

use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Foundation\Bus\Dispatchable;

abstract class BaseJob implements ShouldQueue
{
    use Dispatchable;

    public ?bool $afterCommit = null;
}
