<?php

// This file deliberately omits strict_types. Route parameters arrive as strings, and Laravel's
// dispatchers and container are not strict, so PHP's weak typing turns '123' into 123 for an
// `int $id` action or constructor parameter. Calling application code from here keeps that
// Laravel-compatible behavior, including PHP's own TypeError for a value such as 'abc'.

namespace Hypervel\Container;

/**
 * @internal
 */
final class NativeInvoker
{
    /**
     * Call application code with PHP's weak scalar conversion.
     *
     * @param list<mixed> $arguments
     */
    public static function call(callable $callback, array $arguments): mixed
    {
        return $callback(...$arguments);
    }

    /**
     * Construct a class with PHP's weak scalar conversion.
     *
     * @template TClass of object
     *
     * @param class-string<TClass> $class
     * @param list<mixed> $arguments
     * @return TClass
     */
    public static function construct(string $class, array $arguments): object
    {
        return new $class(...$arguments);
    }
}
