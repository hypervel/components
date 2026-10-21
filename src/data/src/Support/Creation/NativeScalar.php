<?php

// This file deliberately omits strict_types: its return types make PHP apply its own weak scalar
// conversion, so '42' becomes 42 while 'abc' fails with PHP's TypeError, as it would for an
// ordinary weakly typed constructor call.

namespace Hypervel\Data\Support\Creation;

/**
 * @internal
 */
final class NativeScalar
{
    /**
     * Convert a value to an integer with PHP's weak typing rules.
     */
    public static function int(mixed $value): int
    {
        return $value; // @phpstan-ignore return.type
    }

    /**
     * Convert a value to a float with PHP's weak typing rules.
     */
    public static function float(mixed $value): float
    {
        return $value; // @phpstan-ignore return.type
    }

    /**
     * Convert a value to a string with PHP's weak typing rules.
     */
    public static function string(mixed $value): string
    {
        return $value; // @phpstan-ignore return.type
    }

    /**
     * Convert a value to a boolean with PHP's weak typing rules.
     */
    public static function bool(mixed $value): bool
    {
        return $value; // @phpstan-ignore return.type
    }
}
