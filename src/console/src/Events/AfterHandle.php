<?php

declare(strict_types=1);

namespace Hypervel\Console\Events;

use Hypervel\Console\Command;

/**
 * Dispatched inside the coroutine after the command's handle method completes successfully.
 *
 * Only fires when handle() returns without throwing. For failure cases, check
 * AfterExecute's throwable. Unlike CommandFinished (which fires at the Symfony level
 * outside the coroutine), this event runs inside the coroutine where Context is available.
 */
class AfterHandle
{
    public function __construct(
        public readonly Command $command,
    ) {
    }
}
