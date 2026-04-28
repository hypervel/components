<?php

declare(strict_types=1);

namespace Hypervel\Core\Bootstrap;

use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Core\Events\OnReceive;
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
