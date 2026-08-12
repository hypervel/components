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
     * Update the task sub-label. Pass an empty string to clear.
     */
    public function subLabel(string $message): void
    {
        $this->task->updateSubLabel($message);
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
