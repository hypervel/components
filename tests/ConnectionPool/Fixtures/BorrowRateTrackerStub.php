<?php

declare(strict_types=1);

namespace Hypervel\Tests\ConnectionPool\Fixtures;

use Hypervel\ConnectionPool\BorrowRateTracker;

class BorrowRateTrackerStub extends BorrowRateTracker
{
    public int $now = 100;

    public function __construct(int $window = 10, int $threshold = 5, int $cooldown = 60)
    {
        $this->window = $window;
        $this->threshold = $threshold;
        $this->cooldown = $cooldown;
    }

    public function seed(int $startedAt, array $borrows): void
    {
        $this->startedAt = $startedAt;
        $this->lastTrimAt = $startedAt;
        $this->borrows = $borrows;
        $this->borrowCount = array_sum($borrows);
        $this->lastPrunedAt = null;
    }

    public function getSamples(): array
    {
        return $this->borrows;
    }

    public function getBorrowCount(): int
    {
        return $this->borrowCount;
    }

    protected function currentTime(): int
    {
        return $this->now;
    }
}
