<?php

declare(strict_types=1);

namespace Hypervel\Core\Bootstrap;

use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Core\Events\OnPacket;
use Swoole\Server;

class PacketCallback
{
    public function __construct(protected Dispatcher $dispatcher)
    {
    }

    /**
     * Handle the packet event.
     */
    public function onPacket(Server $server, string $data, array $clientInfo): void
    {
        $this->dispatcher->dispatch(new OnPacket($server, $data, $clientInfo));
    }
}
