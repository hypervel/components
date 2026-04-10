<?php

declare(strict_types=1);

namespace Hypervel\Tests\WebSocketServer\Fixtures;

use Hypervel\Contracts\Server\OnOpenInterface;
use RuntimeException;
use Swoole\Http\Request;
use Swoole\WebSocket\Server;

class WebSocketThrowingStub implements OnOpenInterface
{
    public function onOpen(Server $server, Request $request): void
    {
        throw new RuntimeException('onOpen failed');
    }
}
