<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Engine;

interface BarrierInterface
{
    /**
     * Wait for the barrier to be released.
     */
    public static function wait(object &$barrier, int $timeout = -1): void;

    /**
     * Create a new barrier instance.
     */
    public static function create(): object;
}
