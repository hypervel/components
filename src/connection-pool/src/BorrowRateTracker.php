<?php

declare(strict_types=1);

namespace Hypervel\ConnectionPool;

use Hypervel\Contracts\ConnectionPool\UsageTracker;

/**
 * Track recent borrowing activity to shrink pools during periods of low usage.
 */
class BorrowRateTracker implements UsageTracker
{
    /**
     * @var array<int, int>
     */
    protected array $borrows = [];

    protected int $window = 10;

    protected int $threshold = 5;

    protected int $cooldown = 60;

    protected ?int $startedAt = null;

    protected ?int $lastTrimAt = null;

    protected ?int $lastPrunedAt = null;

    protected int $borrowCount = 0;

    /**
     * Record a successful connection borrow.
     */
    public function recordBorrow(): void
    {
        $now = $this->currentTime();

        if ($this->startedAt === null) {
            $this->startedAt = $now;
            $this->lastTrimAt = $now;
        }

        $this->prune($now);
        $this->borrows[$now] = ($this->borrows[$now] ?? 0) + 1;
        ++$this->borrowCount;
    }

    /**
     * Return the average number of borrows per sampled second.
     */
    public function getBorrowRate(): float
    {
        if ($this->startedAt === null) {
            return 0.0;
        }

        $this->prune($this->currentTime());
        $sampleCount = count($this->borrows);

        return $sampleCount === 0 ? 0.0 : $this->borrowCount / $sampleCount;
    }

    /**
     * Determine whether low usage and the cooldown permit trimming.
     */
    public function shouldTrimExcessIdle(): bool
    {
        if ($this->lastTrimAt === null) {
            return false;
        }

        $now = $this->currentTime();

        if ($this->lastTrimAt + $this->cooldown >= $now) {
            return false;
        }

        $this->prune($now);
        $sampleCount = count($this->borrows);

        if (($sampleCount === 0 ? 0.0 : $this->borrowCount / $sampleCount) < $this->threshold) {
            $this->lastTrimAt = $now;

            return true;
        }

        return false;
    }

    /**
     * Remove expired samples and fill completed seconds without borrows.
     */
    protected function prune(int $now): void
    {
        if ($this->lastPrunedAt === $now) {
            return;
        }

        $latest = $now - $this->window + 1;

        foreach ($this->borrows as $second => $count) {
            if ($second < $latest) {
                $this->borrowCount -= $count;
                unset($this->borrows[$second]);
            }
        }

        for ($second = max($this->startedAt, $latest); $second < $now; ++$second) {
            $this->borrows[$second] ??= 0;
        }

        $this->lastPrunedAt = $now;
    }

    /**
     * Return the current sampling second.
     */
    protected function currentTime(): int
    {
        return intdiv(hrtime(true), 1_000_000_000);
    }
}
