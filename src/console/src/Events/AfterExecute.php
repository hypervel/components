<?php

declare(strict_types=1);

namespace Hypervel\Console\Events;

use Hypervel\Console\Command;
use Symfony\Component\Console\Input\InputInterface;
use Throwable;

/**
 * Dispatched inside the command execution boundary after execution completes.
 *
 * Always fires regardless of success or failure. When the command threw an exception,
 * the throwable is available for inspection. Unlike CommandFinished, this event fires
 * within the command's execution boundary. Commands may disable coroutine execution,
 * so listeners must check before using coroutine-only APIs. Framework dispatches
 * always include the input and exit code; they remain nullable only for compatibility
 * with existing manual event construction.
 */
class AfterExecute
{
    public function __construct(
        public readonly Command $command,
        public readonly ?Throwable $throwable = null,
        public readonly ?InputInterface $input = null,
        public readonly ?int $exitCode = null,
    ) {
    }
}
