<?php

declare(strict_types=1);

namespace Hypervel\Queue;

use Hypervel\Bus\Dispatchable;
use Hypervel\Bus\Queueable as QueueableByBus;

trait Queueable
{
    use Dispatchable;
    use InteractsWithQueue;
    use QueueableByBus;
    use SerializesModels;
}
