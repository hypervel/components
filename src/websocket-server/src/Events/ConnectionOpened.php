<?php

declare(strict_types=1);

namespace Hypervel\WebSocketServer\Events;

use Swoole\Http\Request;

class ConnectionOpened
{
    /**
     * Create a new event instance.
     */
    public function __construct(
        public int $fd,
        public Request $request,
        public string $server = 'websocket',
    ) {
    }
}
