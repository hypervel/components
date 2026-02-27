<?php

declare(strict_types=1);

namespace Hypervel\Framework\Bootstrap;

use Hypervel\Contracts\Event\Dispatcher;
use Hypervel\Framework\Events\OnClose;
use Swoole\Server;

class CloseCallback
{
    public function __construct(protected Dispatcher $dispatcher)
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
