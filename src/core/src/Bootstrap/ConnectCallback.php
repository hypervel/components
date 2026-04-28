<?php

declare(strict_types=1);

namespace Hypervel\Core\Bootstrap;

use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Core\Events\OnConnect;
use Swoole\Server;

class ConnectCallback
{
    public function __construct(protected Dispatcher $dispatcher)
    {
    }

    /**
     * Handle the connect event.
     */
    public function onConnect(Server $server, int $fd, int $reactorId): void
    {
        $this->dispatcher->dispatch(new OnConnect($server, $fd, $reactorId));
    }
}
