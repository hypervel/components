<?php

declare(strict_types=1);

namespace Hypervel\Validation;

use Stringable;

/**
 * Keep value-membership comparisons consistent across declarations and execution.
 *
 * @internal
 */
class Membership
{
    /**
     * Normalize a membership value without merging false or null with an empty string.
     *
     * @return ($value is null ? null : string)
     */
    public static function normalize(string|int|float|bool|Stringable|null $value): ?string
    {
        return $value === null ? null : (is_bool($value) ? (string) (int) $value : (string) $value);
    }

    /**
     * Build a lookup of comparable values, keeping null separate from string keys.
     *
     * @return array{array<int|string, true>, bool}
     */
    public static function lookup(array $values): array
    {
        $lookup = [];
        $hasNull = false;

        foreach ($values as $value) {
            if ($value === null) {
                $hasNull = true;
            } elseif (is_scalar($value) || $value instanceof Stringable) {
                $lookup[self::normalize($value)] = true;
            }
        }

        return [$lookup, $hasNull];
    }

    /**
     * Determine if a value belongs to a prepared membership lookup.
     *
     * @param array<int|string, true> $lookup
     */
    public static function contains(array $lookup, bool $hasNull, mixed $value): bool
    {
        return $value === null
            ? $hasNull
            : (is_scalar($value) || $value instanceof Stringable) && isset($lookup[self::normalize($value)]);
    }
}
