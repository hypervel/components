<?php

declare(strict_types=1);

namespace Hypervel\Console\Events;

use Hypervel\Console\Command;
use Symfony\Component\Console\Input\InputInterface;

/**
 * Dispatched inside the command execution boundary, immediately before its handle method is called.
 *
 * Fires after all Symfony/run() setup is complete but before business logic executes.
 * Unlike CommandStarting, this event fires within the command's execution boundary.
 * Commands may disable coroutine execution, so listeners must check before using
 * coroutine-only APIs. Framework dispatches always include the input; it remains
 * nullable only for compatibility with existing manual event construction.
 */
class BeforeHandle
{
    public function __construct(
        public readonly Command $command,
        public readonly ?InputInterface $input = null,
    ) {
    }
}
