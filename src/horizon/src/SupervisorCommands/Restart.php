<?php

declare(strict_types=1);

namespace Hypervel\Horizon\SupervisorCommands;

use Hypervel\Horizon\Contracts\Restartable;

class Restart
{
    /**
     * Process the command.
     */
    public function process(Restartable $restartable): void
    {
        $restartable->restart();
    }
}
