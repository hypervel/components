<?php

declare(strict_types=1);

namespace Hypervel\Framework\Events;

use Swoole\Server;

class OnWorkerError
{
    /**
     * Create a new worker error event instance.
     */
    public function __construct(
        public readonly Server $server,
        public readonly int $workerId,
        public readonly int $workerPid,
        public readonly int $exitCode,
        public readonly int $signal,
    ) {
    }
}
