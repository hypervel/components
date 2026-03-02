<?php

declare(strict_types=1);

namespace Hypervel\Framework\Bootstrap;

use Hypervel\Contracts\Event\Dispatcher;
use Hypervel\Coordinator\Constants;
use Hypervel\Coordinator\CoordinatorManager;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Framework\Events\OnWorkerExit;
use Swoole\Server;

class WorkerExitCallback
{
    public function __construct(protected Dispatcher $dispatcher)
    {
    }

    /**
     * Handle the worker exit event.
     */
    public function onWorkerExit(Server $server, int $workerId): void
    {
        $this->dispatcher->dispatch(new OnWorkerExit($server, $workerId));
        Coroutine::create(function () {
            CoordinatorManager::until(Constants::WORKER_EXIT)->resume();
        });
    }
}
