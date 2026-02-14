<?php

declare(strict_types=1);

namespace Hypervel\Console\Events;

use Hypervel\Console\Command;
use Throwable;

class FailToHandle extends Event
{
    public function __construct(Command $command, protected Throwable $throwable)
    {
        parent::__construct($command);
    }

    /**
     * Get the throwable that caused the command to fail.
     */
    public function getThrowable(): Throwable
    {
        return $this->throwable;
    }
}
