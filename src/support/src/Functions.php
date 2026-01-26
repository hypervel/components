<?php

declare(strict_types=1);

namespace Hypervel\Support;

use BackedEnum;
use Closure;
use Symfony\Component\Process\PhpExecutableFinder;
use UnitEnum;

/**
 * Return the default value of the given value.
 * @template TValue
 * @template TReturn
 *
 * @param (Closure(TValue):TReturn)|TValue $value
 * @return ($value is Closure ? TReturn : TValue)
 */
function value(mixed $value, ...$args)
{
    return $value instanceof Closure ? $value(...$args) : $value;
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
 * @param callable(TValue): TDefault|TDefault $default
 * @return ($value is empty ? TDefault : mixed)
 */
function enum_value($value, $default = null)
{
    return match (true) {
        $value instanceof BackedEnum => $value->value,
        $value instanceof UnitEnum => $value->name,
        default => $value ?? value($default),
    };
}

/**
 * Determine the PHP Binary.
 */
function php_binary(): string
{
    return (new PhpExecutableFinder())->find(false) ?: 'php';
}

/**
 * Gets the value of an environment variable.
 */
function env(string $key, mixed $default = null): mixed
{
    return \Hyperf\Support\env($key, $default);
}
