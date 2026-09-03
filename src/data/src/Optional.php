<?php

declare(strict_types=1);

namespace Hypervel\Data;

class Optional
{
    /**
     * Create a new optional value.
     */
    public static function create(): self
    {
        return new self;
    }
}
