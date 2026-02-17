<?php

declare(strict_types=1);

namespace Hypervel\Framework\Bootstrap;

use Hypervel\Framework\Events\OnConnect;
use Psr\EventDispatcher\EventDispatcherInterface;
use Swoole\Server;

class ConnectCallback
{
    public function __construct(protected EventDispatcherInterface $dispatcher)
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
