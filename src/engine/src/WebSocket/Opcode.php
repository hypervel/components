<?php

declare(strict_types=1);

namespace Hypervel\Engine\WebSocket;

class Opcode
{
    public const CONTINUATION = 0;

    public const TEXT = 1;

    public const BINARY = 2;

    public const CLOSE = 8;

    public const PING = 9;

    public const PONG = 10;
}
