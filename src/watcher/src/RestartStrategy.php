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

    /**
     * Stop the managed process.
     *
     * Idempotent — teardown paths may invoke it multiple times.
     */
    public function stop(): void;
}
