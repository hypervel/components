<?php

declare(strict_types=1);

namespace Hypervel\Support;

use Closure;
use Symfony\Component\Process\PhpExecutableFinder;

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
