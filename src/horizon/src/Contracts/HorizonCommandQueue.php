<?php

declare(strict_types=1);

namespace Hypervel\Horizon\Contracts;

interface HorizonCommandQueue
{
    /**
     * Push a command onto a queue.
     */
    public function push(string $name, string $command, array $options = []): void;

    /**
     * Get the pending commands for a given queue name.
     */
    public function pending(string $name): array;

    /**
     * Flush the command queue for a given queue name.
     */
    public function flush(string $name): void;
}
