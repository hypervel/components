<?php

declare(strict_types=1);

namespace Hypervel\Engine\Constant;

class SocketType
{
    public const TCP = SWOOLE_SOCK_TCP;

    public const TCP6 = SWOOLE_SOCK_TCP6;

    public const UDP = SWOOLE_SOCK_UDP;

    public const UDP6 = SWOOLE_SOCK_UDP6;

    public const UNIX_STREAM = SWOOLE_SOCK_UNIX_STREAM;

    public const UNIX_DGRAM = SWOOLE_SOCK_UNIX_DGRAM;
}
