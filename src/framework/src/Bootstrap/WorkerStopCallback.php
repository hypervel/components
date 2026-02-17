<?php

declare(strict_types=1);

namespace Hypervel\Framework\Bootstrap;

use Hypervel\Framework\Events\OnWorkerStop;
use Psr\EventDispatcher\EventDispatcherInterface;
use Swoole\Server;

class WorkerStopCallback
{
    public function __construct(protected EventDispatcherInterface $dispatcher)
    {
    }

    /**
     * Handle the worker stop event.
     */
    public function onWorkerStop(Server $server, int $workerId): void
    {
        $this->dispatcher->dispatch(new OnWorkerStop($server, $workerId));
    }
}
