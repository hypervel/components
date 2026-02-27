<?php

declare(strict_types=1);

namespace Hypervel\Framework\Bootstrap;

use Hypervel\Contracts\Event\Dispatcher;
use Hypervel\Framework\Events\OnConnect;
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
