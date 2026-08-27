<?php

declare(strict_types=1);

namespace Hypervel\Prompts\Support;

use Hypervel\Prompts\Task;

/**
 * Logger implementation for in-process Task execution.
 *
 * This logger writes directly to the Task instance instead of crossing a
 * process boundary. It is used by static and coroutine task renderers.
 */
class InProcessLogger extends Logger
{
    /**
     * Create a new InProcessLogger instance.
     */
    public function __construct(private Task $task)
    {
        parent::__construct($task->identifier);
    }

    /**
     * Write a message directly to the Task.
     */
    protected function write(string $message, ?string $type = null): void
    {
        $this->task->applyMessage($type, $message);
    }
}
