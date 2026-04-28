<?php

declare(strict_types=1);

namespace Hypervel\Prompts\Support;

use Hypervel\Prompts\Task;

/**
 * Logger implementation for coroutine-based Task execution.
 *
 * Instead of writing to a socket for IPC (which requires pcntl_fork),
 * this logger writes directly to the Task instance's shared state.
 * This is safe because Swoole coroutines are cooperatively scheduled
 * within the same thread and share memory.
 */
class CoroutineLogger extends Logger
{
    /**
     * Create a new CoroutineLogger instance.
     */
    public function __construct(private Task $task)
    {
        parent::__construct($task->identifier);
    }

    /**
     * Log a line to the task output.
     */
    public function line(string $message): void
    {
        $this->task->appendLogLine(rtrim($message));
    }

    /**
     * Log a success message.
     */
    public function success(string $message): void
    {
        $this->task->addStableMessage('success', $message);
    }

    /**
     * Log a warning message.
     */
    public function warning(string $message): void
    {
        $this->task->addStableMessage('warning', $message);
    }

    /**
     * Log an error message.
     */
    public function error(string $message): void
    {
        $this->task->addStableMessage('error', $message);
    }

    /**
     * Update the task label.
     */
    public function label(string $message): void
    {
        $this->task->updateLabel($message);
    }

    /**
     * Append a chunk of text, accumulating on the current line(s).
     */
    public function partial(string $chunk): void
    {
        $this->streamBuffer .= $chunk;
        $this->task->replacePartialText($this->streamBuffer);
    }

    /**
     * Commit the accumulated partial text and start fresh.
     */
    public function commitPartial(): void
    {
        $this->streamBuffer = '';
        $this->task->commitPartialText();
    }
}
