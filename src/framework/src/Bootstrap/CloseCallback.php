<?php

declare(strict_types=1);

namespace Hypervel\Framework\Bootstrap;

use Hypervel\Framework\Events\OnClose;
use Psr\EventDispatcher\EventDispatcherInterface;
use Swoole\Server;

class CloseCallback
{
    public function __construct(protected EventDispatcherInterface $dispatcher)
    {
    }

    /**
     * Handle the close event.
     */
    public function onClose(Server $server, int $fd, int $reactorId): void
    {
        $this->dispatcher->dispatch(new OnClose($server, $fd, $reactorId));
    }
}
