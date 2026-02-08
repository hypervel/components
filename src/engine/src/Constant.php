<?php

declare(strict_types=1);

namespace Hypervel\Engine;

use Swoole\Coroutine\Http\Server as HttpServer;
use Swoole\Coroutine\Server;

class Constant
{
    public const ENGINE = 'Swoole';

    /**
     * Determine if the given server is a coroutine server.
     */
    public static function isCoroutineServer(mixed $server): bool
    {
        return $server instanceof Server || $server instanceof HttpServer;
    }
}
