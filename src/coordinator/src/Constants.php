<?php

declare(strict_types=1);

namespace Hypervel\Coordinator;

class Constants
{
    /**
     * Swoole onWorkerStart event.
     */
    public const string WORKER_START = 'workerStart';

    /**
     * Swoole onWorkerExit event.
     */
    public const string WORKER_EXIT = 'workerExit';
}
