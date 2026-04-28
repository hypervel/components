<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Server;

interface BootstrapsForServer
{
    /**
     * Bootstrap the handler for the given server.
     */
    public function bootstrapForServer(string $serverName): void;
}
