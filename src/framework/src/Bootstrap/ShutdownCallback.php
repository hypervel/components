<?php

declare(strict_types=1);

namespace Hypervel\Framework\Bootstrap;

use Hypervel\Framework\Events\OnShutdown;
use Psr\EventDispatcher\EventDispatcherInterface;
use Swoole\Server;

class ShutdownCallback
{
    public function __construct(protected EventDispatcherInterface $dispatcher)
    {
    }

    /**
     * Handle the server shutdown event.
     */
    public function onShutdown(Server $server): void
    {
        $this->dispatcher->dispatch(new OnShutdown($server));
    }
}
