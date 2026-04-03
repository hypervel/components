<?php

declare(strict_types=1);

namespace Hypervel\Framework\Events;

use Swoole\Server;
use Swoole\Server\Task;

class OnTask
{
    public mixed $result = null;

    /**
     * Create a new task event instance.
     */
    public function __construct(
        public readonly Server $server,
        public readonly Task $task,
    ) {
    }

    /**
     * Set the task result.
     */
    public function setResult(mixed $result): static
    {
        $this->result = $result;
        return $this;
    }
}
