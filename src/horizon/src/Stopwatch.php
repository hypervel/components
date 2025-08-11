<?php

declare(strict_types=1);

namespace Hypervel\Horizon;

class Stopwatch
{
    /**
     * All of the current timers.
     */
    public array $timers = [];

    /**
     * Start a new timer.
     */
    public function start(string $key): void
    {
        $this->timers[$key] = microtime(true);
    }

    /**
     * Check a given timer and get the elapsed time in milliseconds.
     */
    public function check(string $key): ?float
    {
        if (isset($this->timers[$key])) {
            return round((microtime(true) - $this->timers[$key]) * 1000, 2);
        }
    }

    /**
     * Forget a given timer.
     */
    public function forget(string $key): void
    {
        unset($this->timers[$key]);
    }
}
