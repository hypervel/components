<?php

declare(strict_types=1);

namespace Hypervel\Console\Events;

use Hypervel\Console\Command;

/**
 * Dispatched inside the command execution boundary after its handle method completes successfully.
 *
 * Only fires when handle() returns without throwing. For failure cases, check
 * AfterExecute's throwable. Unlike CommandFinished, this event fires within the
 * command's execution boundary. Commands may disable coroutine execution, so
 * listeners must check before using coroutine-only APIs.
 */
class AfterHandle
{
    public function __construct(
        public readonly Command $command,
    ) {
    }
}
