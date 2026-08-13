<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Traits;

trait Makeable
{
    /**
     * Create a new instance with the given arguments.
     */
    public static function make(mixed ...$arguments): static
    {
        return new static(...$arguments);
    }
}
