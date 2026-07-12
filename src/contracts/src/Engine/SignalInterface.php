<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Engine;

interface SignalInterface
{
    /**
     * Wait for a signal.
     */
    public static function wait(int $signo, float $timeout = -1): bool;
}
