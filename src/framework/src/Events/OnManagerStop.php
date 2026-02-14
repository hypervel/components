<?php

declare(strict_types=1);

namespace Hypervel\Framework\Events;

use Swoole\Server;

class OnManagerStop
{
    /**
     * Create a new manager stop event instance.
     */
    public function __construct(
        public readonly Server $server,
    ) {
    }
}
