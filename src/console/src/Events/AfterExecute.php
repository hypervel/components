<?php

declare(strict_types=1);

namespace Hypervel\Console\Events;

use Hypervel\Console\Command;
use Throwable;

class AfterExecute extends Event
{
    public function __construct(Command $command, protected ?Throwable $throwable = null)
    {
        parent::__construct($command);
    }

    /**
     * Get the throwable that occurred during execution, if any.
     */
    public function getThrowable(): ?Throwable
    {
        return $this->throwable;
    }
}
