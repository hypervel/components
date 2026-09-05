<?php

declare(strict_types=1);

namespace Hypervel\Support;

use BackedEnum;
use ReflectionEnum;
use Stringable;
use UnitEnum;
use ValueError;

/**
 * Attempt to create a backed enum from the given value.
 *
 * @internal
 *
 * @template TEnum of BackedEnum
 *
 * @param class-string<TEnum> $enum
 * @return null|TEnum
 */
function enum_try_from(string $enum, mixed $value): ?BackedEnum
{
    if (! enum_exists($enum) || ! is_subclass_of($enum, BackedEnum::class)) {
        return null;
    }

    if ($value instanceof $enum) {
        return $value;
    }

    /** @var array<class-string<BackedEnum>, 'int'|'string'> $backingTypes */
    static $backingTypes = [];

    $backingType = $backingTypes[$enum] ??= (new ReflectionEnum($enum))->getBackingType()->getName();

    if ($backingType === 'int') {
        if (is_bool($value)) {
            $value = (int) $value;
        } elseif (is_string($value)) {
            $value = trim($value);

            if ($value === '' || ! is_numeric($value)) {
                return null;
            }

            // Preserve integer precision while promoting out-of-range numeric strings to floats.
            $value = +$value;
        }

        if (is_int($value)) {
            return $enum::tryFrom($value);
        }

        // PHP_INT_MIN is exactly representable as a float, while PHP_INT_MAX rounds up.
        // Reject non-finite, fractional, and positive-boundary values before casting.
        return is_float($value) && is_finite($value)
            && $value === floor($value)
            && $value >= (float) PHP_INT_MIN && $value < (float) PHP_INT_MAX
                ? $enum::tryFrom((int) $value)
                : null;
    }

    if (is_string($value)) {
        return $enum::tryFrom($value);
    }

    if (is_int($value) || is_float($value) || is_bool($value) || $value instanceof Stringable) {
        return $enum::tryFrom((string) $value);
    }

    return null;
}

/**
 * Create a backed enum from the given value.
 *
 * @internal
 *
 * @template TEnum of BackedEnum
 *
 * @param class-string<TEnum> $enum
 * @return TEnum
 */
function enum_from(string $enum, mixed $value): BackedEnum
{
    return enum_try_from($enum, $value) ?? throw new ValueError(sprintf(
        '%s is not a valid backing value for enum %s',
        is_string($value)
            ? '"' . $value . '"'
            : (is_scalar($value) ? var_export($value, true) : get_debug_type($value)),
        $enum,
    ));
}

/**
 * Return a scalar value for the given value that might be an enum.
 *
 * @internal
 *
 * @template TValue
 * @template TDefault
 *
 * @param TValue $value
 * @param (callable(): TDefault)|TDefault $default
 * @return ($value is empty ? TDefault : mixed)
 */
function enum_value(mixed $value, mixed $default = null): mixed
{
    return match (true) {
        $value instanceof BackedEnum => $value->value,
        $value instanceof UnitEnum => $value->name,

        default => $value ?? value($default),
    };
}
