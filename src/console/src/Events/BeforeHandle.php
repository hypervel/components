<?php

declare(strict_types=1);

namespace Hypervel\Console\Events;

use Hypervel\Console\Command;

/**
 * Dispatched inside the coroutine, immediately before the command's handle method is called.
 *
 * Fires after all Symfony/run() setup is complete but before business logic executes.
 * Unlike CommandStarting (which fires at the Symfony level outside the coroutine),
 * this event runs inside the coroutine where Context is available.
 */
class BeforeHandle
{
    public function __construct(
        public readonly Command $command,
    ) {
    }
}
