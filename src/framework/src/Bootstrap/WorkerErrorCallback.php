<?php

declare(strict_types=1);

namespace Hypervel\Framework\Bootstrap;

use Hypervel\Contracts\Event\Dispatcher;
use Hypervel\Framework\Events\OnWorkerError;
use Swoole\Server;

class WorkerErrorCallback
{
    public function __construct(protected Dispatcher $dispatcher)
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
