<?php

declare(strict_types=1);

namespace Hypervel\Di;

use InvalidArgumentException;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Static cache for reflection objects.
 *
 * Caches ReflectionClass, ReflectionMethod, and ReflectionProperty instances
 * for the worker lifetime to avoid repeated reflection construction costs.
 */
class ReflectionManager
{
    protected static array $container = [];

    /**
     * Get a cached ReflectionClass for the given class name.
     */
    public static function reflectClass(string $className): ReflectionClass
    {
        if (! isset(static::$container['class'][$className])) {
            if (! class_exists($className) && ! interface_exists($className) && ! trait_exists($className)) {
                throw new InvalidArgumentException("Class {$className} does not exist");
            }
            static::$container['class'][$className] = new ReflectionClass($className);
        }
        return static::$container['class'][$className];
    }

    /**
     * Get a cached ReflectionMethod for the given class and method.
     */
    public static function reflectMethod(string $className, string $method): ReflectionMethod
    {
        $key = $className . '::' . $method;
        if (! isset(static::$container['method'][$key])) {
            if (! class_exists($className) && ! interface_exists($className) && ! trait_exists($className)) {
                throw new InvalidArgumentException("Class {$className} does not exist");
            }
            static::$container['method'][$key] = static::reflectClass($className)->getMethod($method);
        }
        return static::$container['method'][$key];
    }

    /**
     * Get a cached ReflectionProperty for the given class and property.
     */
    public static function reflectProperty(string $className, string $property): ReflectionProperty
    {
        $key = $className . '::' . $property;
        if (! isset(static::$container['property'][$key])) {
            if (! class_exists($className)) {
                throw new InvalidArgumentException("Class {$className} does not exist");
            }
            static::$container['property'][$key] = static::reflectClass($className)->getProperty($property);
        }
        return static::$container['property'][$key];
    }

    /**
     * Get all property names for the given class.
     *
     * @return array<int, string>
     */
    public static function reflectPropertyNames(string $className): array
    {
        if (! isset(static::$container['property_names'][$className])) {
            if (! class_exists($className) && ! interface_exists($className) && ! trait_exists($className)) {
                throw new InvalidArgumentException("Class {$className} does not exist");
            }
            $properties = static::reflectClass($className)->getProperties();
            $result = [];
            foreach ($properties as $property) {
                $result[] = $property->getName();
            }
            static::$container['property_names'][$className] = $result;
        }
        return static::$container['property_names'][$className];
    }

    /**
     * Clear all cached reflection data.
     */
    public static function clear(): void
    {
        static::$container = [];
    }

    /**
     * Get the default value of a reflection property.
     */
    public static function getPropertyDefaultValue(ReflectionProperty $property): mixed
    {
        return $property->getDefaultValue();
    }

    /**
     * Get the raw container data.
     */
    public static function getContainer(): array
    {
        return static::$container;
    }
}
