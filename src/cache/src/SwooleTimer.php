<?php

declare(strict_types=1);

namespace Hypervel\Cache;

use Closure;
use Swoole\Timer;

class SwooleTimer
{
    /**
     * Register a Swoole timer tick callback.
     */
    public function tick(int $milliseconds, Closure $callback): int|false
    {
        return Timer::tick($milliseconds, $callback);
    }

    /**
     * Clear a Swoole timer.
     */
    public function clear(int $timerId): bool
    {
        return Timer::clear($timerId);
    }
}
