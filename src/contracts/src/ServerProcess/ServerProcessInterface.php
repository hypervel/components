<?php

declare(strict_types=1);

namespace Hypervel\Contracts\ServerProcess;

use Swoole\Server;

interface ServerProcessInterface
{
    /**
     * Create process objects and bind them to the server.
     */
    public function bind(Server $server): void;

    /**
     * Determine if the process should start.
     */
    public function isEnable(Server $server): bool;

    /**
     * The logic of the process.
     */
    public function handle(): void;
}
