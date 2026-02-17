<?php

declare(strict_types=1);

namespace Hypervel\Framework\Events;

use Swoole\Server;

class BeforeMainServerStart
{
    /**
     * Create a new before main server start event instance.
     */
    public function __construct(
        public readonly Server $server,
        public readonly array $serverConfig,
    ) {
    }
}
