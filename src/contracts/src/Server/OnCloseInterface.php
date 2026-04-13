<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Server;

use Swoole\Server;

interface OnCloseInterface
{
    /**
     * Handle a connection close event.
     */
    public function onClose(Server $server, int $fd, int $reactorId): void;
}
