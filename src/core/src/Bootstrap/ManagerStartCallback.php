<?php

declare(strict_types=1);

namespace Hypervel\Core\Bootstrap;

use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Core\Events\OnManagerStart;
use Swoole\Server as SwooleServer;

class ManagerStartCallback
{
    public function __construct(protected Dispatcher $dispatcher)
    {
    }

    /**
     * Handle the manager start event.
     */
    public function onManagerStart(SwooleServer $server): void
    {
        $this->dispatcher->dispatch(new OnManagerStart($server));
    }
}
