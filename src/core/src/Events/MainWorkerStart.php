<?php

declare(strict_types=1);

namespace Hypervel\Core\Events;

use Swoole\Server;

class MainWorkerStart
{
    /**
     * Create a new main worker start event instance.
     */
    public function __construct(
        public readonly Server $server,
        public readonly int $workerId,
    ) {
    }
}
