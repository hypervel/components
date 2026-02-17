<?php

declare(strict_types=1);

namespace Hypervel\Framework\Events;

use Psr\EventDispatcher\StoppableEventInterface;
use Swoole\Server;
use Swoole\Server\Task;

class OnTask implements StoppableEventInterface
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
     * Set the task result and stop event propagation.
     */
    public function setResult(mixed $result): static
    {
        $this->result = $result;
        return $this;
    }

    /**
     * Determine if event propagation should stop.
     */
    public function isPropagationStopped(): bool
    {
        return ! is_null($this->result);
    }
}
