<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Support;

interface Xmlable
{
    /**
     * Convert the object to its XML representation.
     */
    public function __toString(): string;
}
