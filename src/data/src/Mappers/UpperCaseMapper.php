<?php

declare(strict_types=1);

namespace Hypervel\Data\Mappers;

use Hypervel\Support\Str;

class UpperCaseMapper implements NameMapper
{
    /**
     * Map the given property name.
     */
    public function map(int|string $name): string|int
    {
        return is_int($name) ? $name : Str::upper($name);
    }
}
