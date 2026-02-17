<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Server;

use Swoole\Http\Request;
use Swoole\Http\Response;

interface OnHandShakeInterface
{
    /**
     * Handle the WebSocket handshake.
     */
    public function onHandShake(Request $request, Response $response): void;
}
