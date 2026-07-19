<?php

declare(strict_types=1);

namespace Hypervel\Pool;

/**
 * Interface for frequency trackers that can detect low-frequency usage.
 */
interface LowFrequencyInterface
{
    /**
     * Check if the pool is currently in low-frequency mode.
     */
    public function isLowFrequency(): bool;
}
