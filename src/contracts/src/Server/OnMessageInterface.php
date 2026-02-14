<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Server;

use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server;

interface OnMessageInterface
{
    /**
     * Handle an incoming WebSocket message.
     */
    public function onMessage(Server $server, Frame $frame): void;
}
