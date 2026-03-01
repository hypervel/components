<?php

declare(strict_types=1);

namespace Hypervel\Support;

use Throwable;

class Timebox
{
    public bool $earlyReturn = false;

    /**
     * @template TValue of mixed
     *
     * @param (callable(static): TValue) $callback
     *
     * @return TValue
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
     * @return static
     */
    public function returnEarly(): static
    {
        $this->earlyReturn = true;

        return $this;
    }

    /**
     * @return static
     */
    public function dontReturnEarly(): static
    {
        $this->earlyReturn = false;

        return $this;
    }

    protected function usleep(int $microseconds)
    {
        Sleep::usleep($microseconds);
    }
}
