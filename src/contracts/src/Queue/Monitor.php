<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Queue;

use Hypervel\Support\Attributes\BootOnly;

interface Monitor
{
    /**
     * Register a callback to be executed on every iteration through the queue loop.
     */
    #[BootOnly]
    public function looping(mixed $callback): void;

    /**
     * Register a callback to be executed when a job fails after the maximum number of retries.
     */
    #[BootOnly]
    public function failing(mixed $callback): void;

    /**
     * Register a callback to be executed when a daemon queue is starting.
     */
    #[BootOnly]
    public function starting(mixed $callback): void;

    /**
     * Register a callback to be executed when a daemon queue is stopping.
     */
    #[BootOnly]
    public function stopping(mixed $callback): void;
}
