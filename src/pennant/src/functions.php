<?php

declare(strict_types=1);

namespace Hypervel\Pennant;

use Hypervel\Support;
use UnitEnum;

/**
 * Return a scalar value for the given value that might be an enum.
 *
 * Feature names are strings, so integer-backed enum values are cast.
 *
 * @internal
 */
function enum_value(mixed $value): mixed
{
    return $value instanceof UnitEnum
        ? (string) Support\enum_value($value)
        : $value;
}
