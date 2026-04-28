<?php

declare(strict_types=1);

namespace Hypervel\Core\Bootstrap;

use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Core\Events\OnShutdown;
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
