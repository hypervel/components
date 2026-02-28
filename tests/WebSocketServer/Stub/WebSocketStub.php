<?php

declare(strict_types=1);

namespace Hypervel\Tests\WebSocketServer\Stub;

use Hypervel\Contracts\Server\OnOpenInterface;
use Hypervel\Coroutine\Coroutine;
use Swoole\Http\Request;
use Swoole\WebSocket\Server;

class WebSocketStub implements OnOpenInterface
{
    public static int $coroutineId = 0;

    public function onOpen(Server $server, Request $request): void
    {
        static::$coroutineId = Coroutine::id();
    }
}
