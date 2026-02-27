<?php

declare(strict_types=1);

namespace Hypervel\Framework\Bootstrap;

use Hypervel\Contracts\Event\Dispatcher;
use Hypervel\Framework\Events\OnManagerStop;
use Swoole\Server as SwooleServer;

class ManagerStopCallback
{
    public function __construct(protected Dispatcher $dispatcher)
    {
    }

    /**
     * Handle the manager stop event.
     */
    public function onManagerStop(SwooleServer $server): void
    {
        $this->dispatcher->dispatch(new OnManagerStop($server));
    }
}
