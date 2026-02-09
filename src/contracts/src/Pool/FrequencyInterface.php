<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Pool;

interface FrequencyInterface
{
    /**
     * Record a number of hits.
     */
    public function hit(int $number = 1): bool;

    /**
     * Calculate the average hits per second.
     */
    public function frequency(): float;
}
