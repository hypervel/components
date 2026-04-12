<?php

declare(strict_types=1);

namespace Hypervel\Horizon\SupervisorCommands;

use Hypervel\Horizon\Supervisor;

class Balance
{
    /**
     * Process the command.
     */
    public function process(Supervisor $supervisor, array $options): void
    {
        $supervisor->balance($options);
    }
}
