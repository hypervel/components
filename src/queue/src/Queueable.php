<?php

declare(strict_types=1);

namespace Hypervel\Queue;

use Hypervel\Bus\Queueable as QueueableByBus;
use Hypervel\Foundation\Bus\Dispatchable;

trait Queueable
{
    use Dispatchable;
    use InteractsWithQueue;
    use QueueableByBus;
    use SerializesModels;
}
