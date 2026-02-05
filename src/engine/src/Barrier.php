<?php

declare(strict_types=1);

namespace Hypervel\Engine;

use Hypervel\Contracts\Engine\BarrierInterface;
use Swoole\Coroutine\Barrier as SwooleBarrier;

class Barrier implements BarrierInterface
{
    /**
     * Wait for the barrier to be released.
     */
    public static function wait(object &$barrier, int $timeout = -1): void
    {
        SwooleBarrier::wait($barrier, $timeout);
    }

    /**
     * Create a new barrier instance.
     */
    public static function create(): object
    {
        return SwooleBarrier::make();
    }
}
