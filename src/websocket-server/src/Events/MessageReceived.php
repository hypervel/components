<?php

declare(strict_types=1);

namespace Hypervel\WebSocketServer\Events;

use Swoole\WebSocket\Frame;

class MessageReceived
{
    /**
     * Create a new event instance.
     */
    public function __construct(
        public int $fd,
        public Frame $frame,
        public string $server = 'websocket',
    ) {
    }
}
