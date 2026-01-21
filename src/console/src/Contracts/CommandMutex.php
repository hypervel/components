<?php

declare(strict_types=1);

namespace Hypervel\Console\Contracts;

use Hypervel\Console\Command;

interface CommandMutex
{
    /**
     * Attempt to obtain a command mutex for the given command.
     */
    public function create(Command $command): bool;

    /**
     * Determine if a command mutex exists for the given command.
     */
    public function exists(Command $command): bool;

    /**
     * Release the mutex for the given command.
     */
    public function forget(Command $command): bool;
}
