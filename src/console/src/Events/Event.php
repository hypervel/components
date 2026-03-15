<?php

declare(strict_types=1);

namespace Hypervel\Console\Events;

use Hypervel\Console\Command;

abstract class Event
{
    public function __construct(protected Command $command)
    {
    }

    /**
     * Get the command instance.
     */
    public function getCommand(): Command
    {
        return $this->command;
    }
}
