<?php

declare(strict_types=1);

namespace QueueJobDependency\Jobs;

use Hypervel\Bus\Queueable;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Foundation\Bus\Dispatchable;
use Hypervel\Queue\Attributes\Connection;

#[Connection(Connections::QUEUE)]
class AttributeJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;
}
