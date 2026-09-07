<?php

declare(strict_types=1);

namespace Hypervel\WebSocketServer\Events;

class ConnectionClosing
{
    /**
     * Create a new event instance.
     */
    public function __construct(
        public int $fd,
        public int $reactorId,
        public string $server = 'websocket',
    ) {
    }
}
