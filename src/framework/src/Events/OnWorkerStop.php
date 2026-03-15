<?php

declare(strict_types=1);

namespace Hypervel\Framework\Events;

use Swoole\Server;

class OnWorkerStop
{
    /**
     * Create a new worker stop event instance.
     */
    public function __construct(
        public readonly Server $server,
        public readonly int $workerId,
    ) {
    }
}
