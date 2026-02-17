<?php

declare(strict_types=1);

namespace Hypervel\Framework\Events;

use Swoole\Server;

class OnShutdown
{
    /**
     * Create a new server shutdown event instance.
     */
    public function __construct(
        public readonly Server $server,
    ) {
    }
}
