<?php

declare(strict_types=1);

namespace Hypervel\Framework\Bootstrap;

use Hypervel\Framework\Events\OnManagerStart;
use Psr\EventDispatcher\EventDispatcherInterface;
use Swoole\Server as SwooleServer;

class ManagerStartCallback
{
    public function __construct(protected EventDispatcherInterface $dispatcher)
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
