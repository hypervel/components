<?php

declare(strict_types=1);

namespace Hypervel\Framework\Bootstrap;

use Hypervel\Framework\Events\OnWorkerError;
use Psr\EventDispatcher\EventDispatcherInterface;
use Swoole\Server;

class WorkerErrorCallback
{
    public function __construct(protected EventDispatcherInterface $dispatcher)
    {
    }

    /**
     * Handle the worker error event.
     */
    public function onWorkerError(Server $server, int $workerId, int $workerPid, int $exitCode, int $signal): void
    {
        $this->dispatcher->dispatch(new OnWorkerError($server, $workerId, $workerPid, $exitCode, $signal));
    }
}
