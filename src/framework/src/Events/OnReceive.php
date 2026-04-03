<?php

declare(strict_types=1);

namespace Hypervel\Framework\Events;

use Swoole\Server;

class OnReceive
{
    /**
     * Create a new data received event instance.
     */
    public function __construct(
        public readonly Server $server,
        public readonly int $fd,
        public readonly int $reactorId,
        public readonly mixed $data,
    ) {
    }
}
