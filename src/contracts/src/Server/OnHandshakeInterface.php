<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Server;

use Swoole\Http\Request;
use Swoole\Http\Response;

interface OnHandshakeInterface
{
    /**
     * Handle the WebSocket handshake.
     */
    public function onHandshake(Request $request, Response $response): void;
}
