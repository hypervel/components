<?php

declare(strict_types=1);

namespace Hypervel\Support;

use Exception;
use ReflectionClass;
use ReflectionProperty;

class ClassMetadataCache
{
    /**
     * @var array<class-string, ReflectionClass<object>>
     */
    protected static array $classes = [];

    /**
     * @var array<class-string, array<string, mixed>>
     */
    protected static array $defaultProperties = [];

    /**
     * @var array<class-string, list<ReflectionProperty>>
     */
    protected static array $properties = [];

    /**
     * @var array<class-string, array<class-string, ?CachedClassAttribute>>
     */
    protected static array $attributes = [];

    /**
     * @var array<class-string, array<class-string, bool>>
     */
    protected static array $classAttributePresence = [];

    /**
     * @var array<class-string, array<string, array<class-string, bool>>>
     */
    protected static array $propertyAttributePresence = [];

    /**
     * Get the cached reflection class for the given class.
     *
     * @param class-string|object $target
     * @return ReflectionClass<object>
     */
    public static function reflectClass(object|string $target): ReflectionClass
    {
        $class = static::className($target);

        return static::$classes[$class] ??= new ReflectionClass($class);
    }

    /**
     * Get the cached default properties for the given class.
     *
     * @param class-string|object $target
     * @return array<string, mixed>
     */
    public static function defaultProperties(object|string $target): array
    {
        $class = static::className($target);

        return static::$defaultProperties[$class]
            ??= static::reflectClass($class)->getDefaultProperties();
    }

    /**
     * Get the cached properties for the given class.
     *
     * @param class-string|object $target
     * @return list<ReflectionProperty>
     */
    public static function properties(object|string $target): array
    {
        $class = static::className($target);

        return static::$properties[$class]
            ??= static::reflectClass($class)->getProperties();
    }

    /**
     * Get the cached class attribute metadata for the given class.
     *
     * @param class-string|object $target
     * @param class-string $attributeClass
     */
    public static function getAttribute(object|string $target, string $attributeClass): ?CachedClassAttribute
    {
        $class = static::className($target);

        if (! array_key_exists($class, static::$attributes)) {
            static::$attributes[$class] = [];
        }

        if (array_key_exists($attributeClass, static::$attributes[$class])) {
            return static::$attributes[$class][$attributeClass];
        }

        return static::$attributes[$class][$attributeClass] = static::resolveAttribute($class, $attributeClass);
    }

    /**
     * Determine if the given class has the given concrete class attribute.
     *
     * @param class-string|object $target
     * @param class-string $attributeClass
     */
    public static function hasClassAttribute(object|string $target, string $attributeClass): bool
    {
        $class = static::className($target);

        if (! array_key_exists($class, static::$classAttributePresence)) {
            static::$classAttributePresence[$class] = [];
        }

        if (array_key_exists($attributeClass, static::$classAttributePresence[$class])) {
            return static::$classAttributePresence[$class][$attributeClass];
        }

        return static::$classAttributePresence[$class][$attributeClass]
            = static::reflectClass($class)->getAttributes($attributeClass) !== [];
    }

    /**
     * Determine if the given property has the given concrete property attribute.
     *
     * @param class-string $attributeClass
     */
    public static function hasPropertyAttribute(ReflectionProperty $property, string $attributeClass): bool
    {
        $class = $property->getDeclaringClass()->getName();
        $propertyName = $property->getName();

        if (! array_key_exists($class, static::$propertyAttributePresence)) {
            static::$propertyAttributePresence[$class] = [];
        }

        if (! array_key_exists($propertyName, static::$propertyAttributePresence[$class])) {
            static::$propertyAttributePresence[$class][$propertyName] = [];
        }

        if (array_key_exists($attributeClass, static::$propertyAttributePresence[$class][$propertyName])) {
            return static::$propertyAttributePresence[$class][$propertyName][$attributeClass];
        }

        return static::$propertyAttributePresence[$class][$propertyName][$attributeClass]
            = $property->getAttributes($attributeClass) !== [];
    }

    /**
     * Resolve the class attribute metadata for the given class.
     *
     * @param class-string $class
     * @param class-string $attributeClass
     */
    protected static function resolveAttribute(string $class, string $attributeClass): ?CachedClassAttribute
    {
        $reflection = static::reflectClass($class);

        try {
            do {
                $attributes = $reflection->getAttributes($attributeClass);

                if ($attributes !== []) {
                    return new CachedClassAttribute($attributes[0]->newInstance(), $reflection);
                }

                foreach ($reflection->getTraits() as $trait) {
                    $attributes = $trait->getAttributes($attributeClass);

                    if ($attributes !== []) {
                        return new CachedClassAttribute($attributes[0]->newInstance(), $reflection);
                    }
                }

                $reflection = $reflection->getParentClass();
            } while ($reflection !== false);
        } catch (Exception) {
        }

        return null;
    }

    /**
     * Get the class name for the given target.
     *
     * @param class-string|object $target
     * @return class-string
     */
    protected static function className(object|string $target): string
    {
        return is_object($target) ? $target::class : $target;
    }

    /**
     * Flush all static state.
     */
    public static function flushState(): void
    {
        static::$classes = [];
        static::$defaultProperties = [];
        static::$properties = [];
        static::$attributes = [];
        static::$classAttributePresence = [];
        static::$propertyAttributePresence = [];
    }
}
