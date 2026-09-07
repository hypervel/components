<?php

declare(strict_types=1);

namespace Hypervel\Data\Mappers;

interface NameMapper
{
    /**
     * Map a property name.
     */
    public function map(string|int $name): string|int;
}
