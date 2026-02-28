<?php

declare(strict_types=1);

namespace Hypervel\Pool;

/**
 * Interface for frequency trackers that can detect low-frequency usage.
 */
interface LowFrequencyInterface
{
    public function __construct(?Pool $pool = null);

    /**
     * Check if the pool is currently in low-frequency mode.
     */
    public function isLowFrequency(): bool;
}
