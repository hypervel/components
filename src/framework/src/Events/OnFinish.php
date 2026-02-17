<?php

declare(strict_types=1);

namespace Hypervel\Framework\Events;

use Swoole\Server;

class OnFinish
{
    /**
     * Create a new task finish event instance.
     */
    public function __construct(
        public readonly Server $server,
        public readonly int $taskId,
        public readonly mixed $data,
    ) {
    }
}
