<?php

declare(strict_types=1);

namespace Hypervel\Framework\Bootstrap;

use Hypervel\Contracts\Event\Dispatcher;
use Hypervel\Framework\Events\OnShutdown;
use Swoole\Server;

class ShutdownCallback
{
    public function __construct(protected Dispatcher $dispatcher)
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
