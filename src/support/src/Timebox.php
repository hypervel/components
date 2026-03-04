<?php

declare(strict_types=1);

namespace Hypervel\Support;

use Throwable;

class Timebox
{
    /**
     * Indicates if the timebox is allowed to return early.
     */
    public bool $earlyReturn = false;

    /**
     * Invoke the given callback within the specified timebox minimum.
     *
     * @template TCallReturnType
     *
     * @param (callable($this): TCallReturnType) $callback
     * @return TCallReturnType
     *
     * @throws Throwable
     */
    public function call(callable $callback, int $microseconds): mixed
    {
        $exception = null;

        $start = microtime(true);

        try {
            $result = $callback($this);
        } catch (Throwable $caught) {
            $exception = $caught;
        }

        $remainder = (int) ($microseconds - ((microtime(true) - $start) * 1_000_000));

        if (! $this->earlyReturn && $remainder > 0) {
            $this->usleep($remainder);
        }

        if ($exception) {
            throw $exception;
        }

        return $result;
    }

    /**
     * Indicate that the timebox can return early.
     */
    public function returnEarly(): static
    {
        $this->earlyReturn = true;

        return $this;
    }

    /**
     * Indicate that the timebox cannot return early.
     */
    public function dontReturnEarly(): static
    {
        $this->earlyReturn = false;

        return $this;
    }

    /**
     * Sleep for the specified number of microseconds.
     */
    protected function usleep(int $microseconds): void
    {
        Sleep::usleep($microseconds);
    }
}
