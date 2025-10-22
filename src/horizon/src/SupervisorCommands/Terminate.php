<?php

declare(strict_types=1);

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
