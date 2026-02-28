<?php

declare(strict_types=1);

namespace Hypervel\Pool;

use Hypervel\Coordinator\Timer;

/**
 * A frequency implementation that flushes connections at a constant interval.
 *
 * Unlike Frequency which tracks actual usage, this periodically flushes
 * one connection regardless of usage patterns.
 */
class ConstantFrequency implements LowFrequencyInterface
{
    protected Timer $timer;

    protected ?int $timerId = null;

    /**
     * Flush interval in milliseconds.
     */
    protected int $interval = 10000;

    public function __construct(
        protected ?Pool $pool = null
    ) {
        $this->timer = new Timer();

        if ($pool) {
            $this->timerId = $this->timer->tick(
                $this->interval / 1000,
                fn () => $this->pool->flushOne()
            );
        }
    }

    public function __destruct()
    {
        $this->clear();
    }

    /**
     * Clear the timer.
     */
    public function clear(): void
    {
        if ($this->timerId) {
            $this->timer->clear($this->timerId);
        }

        $this->timerId = null;
    }

    /**
     * Always returns false since flushing is handled by the timer.
     */
    public function isLowFrequency(): bool
    {
        return false;
    }
}
