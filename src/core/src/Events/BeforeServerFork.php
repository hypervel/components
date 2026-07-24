<?php

declare(strict_types=1);

namespace Hypervel\Core\Events;

use Swoole\Server;

class BeforeServerFork
{
    /**
     * Create a new before server fork event instance.
     *
     * Listeners must release parent-only runtime resources and must not open
     * sockets, timers, pools, or other resources that child processes could
     * inherit.
     */
    public function __construct(
        public readonly Server $server,
    ) {
    }
}
