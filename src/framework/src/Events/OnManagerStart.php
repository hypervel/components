<?php

declare(strict_types=1);

namespace Hypervel\Framework\Events;

use Swoole\Server;

class OnManagerStart
{
    /**
     * Create a new manager start event instance.
     */
    public function __construct(
        public readonly Server $server,
    ) {
    }
}
