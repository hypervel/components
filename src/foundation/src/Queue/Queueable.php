<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Queue;

use Hypervel\Bus\Queueable as QueueableByBus;
use Hypervel\Foundation\Bus\Dispatchable;
use Hypervel\Queue\InteractsWithQueue;
use Hypervel\Queue\SerializesModels;

trait Queueable
{
    use Dispatchable;
    use InteractsWithQueue;
    use QueueableByBus;
    use SerializesModels;
}
