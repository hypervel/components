<?php

declare(strict_types=1);

namespace Hypervel\Engine\Constants;

class SocketType
{
    public const int TCP = SWOOLE_SOCK_TCP;

    public const int TCP6 = SWOOLE_SOCK_TCP6;

    public const int UDP = SWOOLE_SOCK_UDP;

    public const int UDP6 = SWOOLE_SOCK_UDP6;

    public const int UNIX_STREAM = SWOOLE_SOCK_UNIX_STREAM;

    public const int UNIX_DGRAM = SWOOLE_SOCK_UNIX_DGRAM;
}
