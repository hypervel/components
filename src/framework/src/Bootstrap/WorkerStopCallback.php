<?php

declare(strict_types=1);

namespace Hypervel\Framework\Bootstrap;

use Hypervel\Contracts\Event\Dispatcher;
use Hypervel\Framework\Events\OnWorkerStop;
use Swoole\Server;

class WorkerStopCallback
{
    public function __construct(protected Dispatcher $dispatcher)
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
