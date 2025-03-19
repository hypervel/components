<?php

declare(strict_types=1);

namespace Hypervel\Queue\Contracts;

interface Monitor
{
    /**
     * Register a callback to be executed on every iteration through the queue loop.
     */
    public function looping(mixed $callback): void;

    /**
     * Register a callback to be executed when a job fails after the maximum number of retries.
     */
    public function failing(mixed $callback): void;

    /**
     * Register a callback to be executed when a daemon queue is stopping.
     */
    public function stopping(mixed $callback): void;
}
