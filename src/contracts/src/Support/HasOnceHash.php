<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Support;

interface HasOnceHash
{
    /**
     * Compute the hash that should be used to represent the object when given to a function using "once".
     */
    public function onceHash(): string;
}
