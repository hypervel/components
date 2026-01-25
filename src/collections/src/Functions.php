<?php

declare(strict_types=1);

namespace Hypervel\Support;

/**
 * Return a scalar value for the given value that might be an enum.
 *
 * @internal
 *
 * @template TValue
 * @template TDefault
 *
 * @param  TValue  $value
 * @param  TDefault|callable(TValue): TDefault  $default
 * @return ($value is empty ? TDefault : mixed)
 */
function enum_value(mixed $value, mixed $default = null): mixed
{
    return match (true) {
        $value instanceof \BackedEnum => $value->value,
        $value instanceof \UnitEnum => $value->name,

        default => $value ?? value($default),
    };
}
