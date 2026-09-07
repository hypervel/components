<?php

declare(strict_types=1);

namespace Hypervel\WebSocketServer\Constants;

class Opcode
{
    public const int CONTINUATION = 0x0;

    public const int TEXT = 0x1;

    public const int BINARY = 0x2;

    public const int CLOSE = 0x8;

    public const int PING = 0x9;

    public const int PONG = 0xA;
}
