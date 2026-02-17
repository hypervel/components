<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Server;

interface MiddlewareInitializerInterface
{
    /**
     * Initialize the core middleware for the given server.
     */
    public function initCoreMiddleware(string $serverName): void;
}
