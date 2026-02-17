<?php

declare(strict_types=1);

namespace Hypervel\WebSocketServer\Constant;

class Opcode
{
    public const CONTINUATION = 0x0;

    public const TEXT = 0x1;

    public const BINARY = 0x2;

    public const CLOSE = 0x8;

    public const PING = 0x9;

    public const PONG = 0xA;
}
