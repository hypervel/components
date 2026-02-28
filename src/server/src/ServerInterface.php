<?php

declare(strict_types=1);

namespace Hypervel\Server;

use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Event\Dispatcher;
use Psr\Log\LoggerInterface;
use Swoole\Server as SwooleServer;

interface ServerInterface
{
    public const SERVER_HTTP = 1;

    public const SERVER_WEBSOCKET = 2;

    public const SERVER_BASE = 3;

    /**
     * Create a new server instance.
     */
    public function __construct(Container $container, LoggerInterface $logger, Dispatcher $dispatcher);

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
