<?php

declare(strict_types=1);

namespace Hypervel\Framework\Bootstrap;

use Hypervel\Contracts\Event\Dispatcher;
use Hypervel\Framework\Events\OnReceive;
use Swoole\Server;

class ReceiveCallback
{
    public function __construct(protected Dispatcher $dispatcher)
    {
    }

    /**
     * Handle the receive event.
     */
    public function onReceive(Server $server, int $fd, int $reactorId, string $data): void
    {
        $this->dispatcher->dispatch(new OnReceive($server, $fd, $reactorId, $data));
    }
}
