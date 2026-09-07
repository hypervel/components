<?php

declare(strict_types=1);

namespace Hypervel\Data\Casts;

interface Castable
{
    /**
     * Create the cast for this type.
     *
     * @param list<mixed> $arguments
     */
    public static function dataCastUsing(array $arguments): Cast;
}
