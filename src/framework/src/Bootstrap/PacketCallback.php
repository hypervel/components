<?php

declare(strict_types=1);

namespace Hypervel\Framework\Bootstrap;

use Hypervel\Contracts\Event\Dispatcher;
use Hypervel\Framework\Events\OnPacket;
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
