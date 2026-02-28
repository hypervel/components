<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Engine;

interface SignalInterface
{
    public static function wait(int $signo, float $timeout = -1): bool;
}
