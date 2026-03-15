<?php

declare(strict_types=1);

namespace Hypervel\Engine;

use Hypervel\Contracts\Engine\SignalInterface;
use Swoole\Coroutine\System;

class Signal implements SignalInterface
{
    /**
     * Wait for a signal.
     */
    public static function wait(int $signo, float $timeout = -1): bool
    {
        return System::waitSignal($signo, $timeout) !== false;
    }
}
