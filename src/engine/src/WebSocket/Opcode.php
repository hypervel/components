<?php

declare(strict_types=1);

namespace Hypervel\Engine\WebSocket;

class Opcode
{
    public const int CONTINUATION = 0;

    public const int TEXT = 1;

    public const int BINARY = 2;

    public const int CLOSE = 8;

    public const int PING = 9;

    public const int PONG = 10;
}
