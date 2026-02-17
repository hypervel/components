<?php

declare(strict_types=1);

namespace Hypervel\Framework\Events;

use Swoole\Server;

class OnPacket
{
    /**
     * Create a new UDP packet received event instance.
     */
    public function __construct(
        public readonly Server $server,
        public readonly string $data,
        public readonly array $clientInfo,
    ) {
    }
}
