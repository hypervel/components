<?php

declare(strict_types=1);

namespace Hypervel\Pool;

use Hypervel\Contracts\Pool\FrequencyInterface;

/**
 * Tracks connection frequency to enable low-frequency pool flushing.
 *
 * Records hits over a time window and calculates average frequency
 * to determine if the pool is in low-frequency mode.
 */
class Frequency implements FrequencyInterface, LowFrequencyInterface
{
    /**
     * Hit counts by timestamp.
     *
     * @var array<int, int>
     */
    protected array $hits = [];

    /**
     * Time window in seconds for frequency calculation.
     */
    protected int $time = 10;

    /**
     * Threshold below which frequency is considered "low".
     */
    protected int $lowFrequency = 5;

    /**
     * Time when frequency tracking began.
     */
    protected int $beginTime;

    /**
     * Last time low frequency was triggered.
     */
    protected int $lowFrequencyTime;

    /**
     * Minimum interval between low frequency triggers.
     */
    protected int $lowFrequencyInterval = 60;

    public function __construct(
        protected ?Pool $pool = null
    ) {
        $this->beginTime = time();
        $this->lowFrequencyTime = time();
    }

    /**
     * Record a hit.
     */
    public function hit(int $number = 1): bool
    {
        $this->flush();

        $now = time();
        $hit = $this->hits[$now] ?? 0;
        $this->hits[$now] = $number + $hit;

        return true;
    }

    /**
     * Calculate the average frequency over the time window.
     */
    public function frequency(): float
    {
        $this->flush();

        $hits = 0;
        $count = 0;

        foreach ($this->hits as $hit) {
            ++$count;
            $hits += $hit;
        }

        return floatval($hits / $count);
    }

    /**
     * Check if currently in low frequency mode.
     */
    public function isLowFrequency(): bool
    {
        $now = time();

        if ($this->lowFrequencyTime + $this->lowFrequencyInterval < $now && $this->frequency() < $this->lowFrequency) {
            $this->lowFrequencyTime = $now;

            return true;
        }

        return false;
    }

    /**
     * Flush old hits outside the time window.
     */
    protected function flush(): void
    {
        $now = time();
        $latest = $now - $this->time;

        foreach ($this->hits as $time => $hit) {
            if ($time < $latest) {
                unset($this->hits[$time]);
            }
        }

        if (count($this->hits) < $this->time) {
            $beginTime = max($this->beginTime, $latest);
            for ($i = $beginTime; $i < $now; ++$i) {
                $this->hits[$i] = $this->hits[$i] ?? 0;
            }
        }
    }
}
