<?php

declare(strict_types=1);

namespace Hypervel\Server;

use Swoole\Server as SwooleServer;

interface ServerInterface
{
    public const SERVER_HTTP = 1;

    public const SERVER_WEBSOCKET = 2;

    public const SERVER_BASE = 3;

    /**
     * Initialize the server with the given configuration.
     */
    public function init(ServerConfig $config): ServerInterface;

    /**
     * Start the server.
     */
    public function start(): void;

    /**
     * Get the underlying Swoole server instance.
     */
    public function getServer(): SwooleServer;
}
