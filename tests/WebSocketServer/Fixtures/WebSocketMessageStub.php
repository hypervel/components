<?php

declare(strict_types=1);

namespace Hypervel\Tests\WebSocketServer\Fixtures;

use Hypervel\Contracts\Server\OnCloseInterface;
use Hypervel\Contracts\Server\OnMessageInterface;
use Swoole\Server;
use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server as WebSocketServer;

class WebSocketMessageStub implements OnMessageInterface, OnCloseInterface
{
    public static bool $messageHandled = false;

    public static bool $closeHandled = false;

    public function onMessage(WebSocketServer $server, Frame $frame): void
    {
        static::$messageHandled = true;
    }

    public function onClose(Server $server, int $fd, int $reactorId): void
    {
        static::$closeHandled = true;
    }

    public static function flushState(): void
    {
        static::$messageHandled = false;
        static::$closeHandled = false;
    }
}
