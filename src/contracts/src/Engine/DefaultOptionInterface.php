<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Engine;

interface DefaultOptionInterface
{
    /**
     * Get Hook Coroutine Flags.
     */
    public static function hookFlags(): int;
}
