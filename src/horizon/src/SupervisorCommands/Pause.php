<?php

declare(strict_types=1);

namespace Hypervel\Horizon\SupervisorCommands;

use Hypervel\Horizon\Contracts\Pausable;

class Pause
{
    /**
     * Process the command.
     */
    public function process(Pausable $pausable): void
    {
        $pausable->pause();
    }
}
