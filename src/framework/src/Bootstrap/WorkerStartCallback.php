<?php

declare(strict_types=1);

namespace Hypervel\Framework\Bootstrap;

use Hypervel\Contracts\Log\StdoutLoggerInterface;
use Hypervel\Coordinator\Constants;
use Hypervel\Coordinator\CoordinatorManager;
use Hypervel\Framework\Events\AfterWorkerStart;
use Hypervel\Framework\Events\BeforeWorkerStart;
use Hypervel\Framework\Events\MainWorkerStart;
use Hypervel\Framework\Events\OtherWorkerStart;
use Psr\EventDispatcher\EventDispatcherInterface;
use Swoole\Server as SwooleServer;

class WorkerStartCallback
{
    public function __construct(protected EventDispatcherInterface $dispatcher, protected StdoutLoggerInterface $logger)
    {
    }

    /**
     * Handle the worker start event.
     */
    public function onWorkerStart(SwooleServer $server, int $workerId): void
    {
        $this->dispatcher->dispatch(new BeforeWorkerStart($server, $workerId));

        if ($workerId === 0) {
            $this->dispatcher->dispatch(new MainWorkerStart($server, $workerId));
        } else {
            $this->dispatcher->dispatch(new OtherWorkerStart($server, $workerId));
        }

        if ($server->taskworker) {
            $this->logger->info("TaskWorker#{$workerId} started.");
        } else {
            $this->logger->info("Worker#{$workerId} started.");
        }

        $this->dispatcher->dispatch(new AfterWorkerStart($server, $workerId));
        CoordinatorManager::until(Constants::WORKER_START)->resume();
    }
}
