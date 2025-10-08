<?php

namespace Hypervel\Horizon\SupervisorCommands;

use Hypervel\Horizon\Contracts\Terminable;

class Terminate
{
    /**
     * Process the command.
     */
    public function process(Terminable $terminable, array $options): void
    {
        $terminable->terminate($options['status'] ?? 0);
    }
}
