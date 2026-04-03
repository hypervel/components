<?php

declare(strict_types=1);

namespace Hypervel\WebSocketServer\Events;

use Swoole\Http\Request;
use Swoole\WebSocket\Server;

class OnOpenEvent
{
    public string $class;

    public Server $server;

    public Request $request;
}
