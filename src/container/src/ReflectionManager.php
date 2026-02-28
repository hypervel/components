<?php

declare(strict_types=1);

namespace Hypervel\Container;

use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Static cache for reflection objects.
 *
 * Reflection is expensive. This caches reflection objects for the worker
 * lifetime, eliminating repeated reflection overhead in Swoole's long-running
 * process model. Cleared in Container::flush() for test isolation.
 */
class ReflectionManager
{
    /**
     * @var array{class?: array<string, ReflectionClass<object>>, method?: array<string, ReflectionMethod>, property?: array<string, ReflectionProperty>}
     */
    protected static array $container = [];

    /**
     * Get a cached ReflectionClass for the given class name.
     *
     * @template T of object
     *
     * @param class-string<T> $className
     * @return ReflectionClass<T>
     *
     * @throws ReflectionException
     */
    public static function reflectClass(string $className): ReflectionClass
    {
        return static::$container['class'][$className] // @phpstan-ignore return.type
            ??= new ReflectionClass($className);
    }

    /**
     * Get a cached ReflectionMethod for the given class and method.
     *
     * @throws ReflectionException
     */
    public static function reflectMethod(string $className, string $method): ReflectionMethod
    {
        $key = $className . '::' . $method;

        return static::$container['method'][$key]
            ??= static::reflectClass($className)->getMethod($method);
    }

    /**
     * Get a cached ReflectionProperty for the given class and property.
     *
     * @throws ReflectionException
     */
    public static function reflectProperty(string $className, string $property): ReflectionProperty
    {
        $key = $className . '::' . $property;

        return static::$container['property'][$key]
            ??= static::reflectClass($className)->getProperty($property);
    }

    /**
     * Clear all cached reflection objects.
     */
    public static function clear(): void
    {
        static::$container = [];
    }
}
