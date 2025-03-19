<?php

declare(strict_types=1);

namespace Hypervel\ObjectPool;

interface LowFrequencyInterface
{
    public function __construct(?ObjectPool $pool = null);

    public function isLowFrequency(): bool;
}
