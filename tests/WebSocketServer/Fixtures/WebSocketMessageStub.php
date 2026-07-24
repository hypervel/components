<?php

declare(strict_types=1);

namespace Hypervel\Tests\WebSocketServer\Fixtures;

use Hypervel\Contracts\Server\OnCloseInterface;
use Hypervel\Contracts\Server\OnMessageInterface;
use Swoole\Server;
use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server as WebSocketServer;
use Throwable;

class WebSocketMessageStub implements OnMessageInterface, OnCloseInterface
{
    public static bool $messageHandled = false;

    public static bool $closeHandled = false;

    public static ?Throwable $messageException = null;

    public static ?Throwable $closeException = null;

    public function onMessage(WebSocketServer $server, Frame $frame): void
    {
        static::$messageHandled = true;

        if (static::$messageException !== null) {
            throw static::$messageException;
        }
    }

    public function onClose(Server $server, int $fd, int $reactorId): void
    {
        static::$closeHandled = true;

        if (static::$closeException !== null) {
            throw static::$closeException;
        }
    }

    public static function flushState(): void
    {
        static::$messageHandled = false;
        static::$closeHandled = false;
        static::$messageException = null;
        static::$closeException = null;
    }
}
