<?php

declare(strict_types=1);

namespace Hypervel\Console\Events;

use Hypervel\Console\Command;
use Throwable;

/**
 * Dispatched inside the coroutine in the finally block after command execution completes.
 *
 * Always fires regardless of success or failure. When the command threw an exception,
 * the throwable is available for inspection. Unlike CommandFinished (which fires at the
 * Symfony level outside the coroutine), this event runs inside the coroutine where
 * Context is available.
 */
class AfterExecute
{
    public function __construct(
        public readonly Command $command,
        public readonly ?Throwable $throwable = null,
    ) {
    }
}
