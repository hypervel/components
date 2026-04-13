<?php

declare(strict_types=1);

namespace Hypervel\Watcher;

interface RestartStrategy
{
    /**
     * Perform the initial start of the managed process.
     */
    public function start(): void;

    /**
     * Restart the managed process (stop current instance, start new).
     */
    public function restart(): void;
}
