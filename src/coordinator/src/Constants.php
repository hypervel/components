<?php

declare(strict_types=1);

namespace Hypervel\Coordinator;

class Constants
{
    /**
     * Swoole onWorkerStart event.
     */
    public const WORKER_START = 'workerStart';

    /**
     * Swoole onWorkerExit event.
     */
    public const WORKER_EXIT = 'workerExit';
}
