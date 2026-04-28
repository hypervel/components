<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Server;

use Swoole\Http\Request;
use Swoole\WebSocket\Server;

interface OnOpenInterface
{
    /**
     * Handle a new WebSocket connection.
     */
    public function onOpen(Server $server, Request $request): void;
}
