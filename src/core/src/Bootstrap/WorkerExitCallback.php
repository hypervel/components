<?php

declare(strict_types=1);

namespace Hypervel\Core\Bootstrap;

use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Coordinator\Constants;
use Hypervel\Coordinator\CoordinatorManager;
use Hypervel\Core\Events\OnWorkerExit;
use Swoole\Server;

class WorkerExitCallback
{
    protected bool $dispatched = false;

    public function __construct(protected Dispatcher $dispatcher)
    {
    }

    /**
     * Handle the worker exit event.
     */
    public function onWorkerExit(Server $server, int $workerId): void
    {
        if ($this->dispatched) {
            return;
        }

        $this->dispatched = true;

        try {
            $this->dispatcher->dispatch(new OnWorkerExit($server, $workerId));
        } finally {
            CoordinatorManager::until(Constants::WORKER_EXIT)->resume();
        }
    }
}
