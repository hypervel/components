<?php

declare(strict_types=1);

namespace Hypervel\Tinker;

use Psy\ExecutionLoop\SignalHandler;
use Psy\Shell;

class ExecuteShell extends Shell
{
    /**
     * Get the default execution loop listeners.
     */
    protected function getDefaultLoopListeners(): array
    {
        return array_filter(
            parent::getDefaultLoopListeners(),
            static fn (object $listener): bool => ! $listener instanceof SignalHandler,
        );
    }
}
