<?php

declare(strict_types=1);

namespace Hypervel\Core\Events;

use Swoole\Server;
use Swoole\Server\Task;

class TaskTerminated
{
    /**
     * Create a new task terminated event instance.
     */
    public function __construct(
        public readonly Server $server,
        public readonly Task $task,
    ) {
    }
}
