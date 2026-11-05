<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Reflection;

use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\Mixin\MixinMethodsClassReflectionExtension;
use PHPStan\Reflection\Mixin\MixinPropertiesClassReflectionExtension;

use function array_key_exists;

final class ReflectionHelper
{
    /** @var array<string, bool> */
    private static array $propertyTagCache = [];

    /** @var array<string, bool> */
    private static array $methodTagCache = [];

    /**
     * Determine whether the given class or its ancestors declare the property with an `@property*` tag, or a mixin has it.
     */
    public static function hasPropertyTag(ClassReflection $classReflection, string $propertyName): bool
    {
        // Hypervel: keyed by the specialization, because a generic class's mixin can depend on its template types.
        $cacheKey = $classReflection->getCacheKey() . '-' . $propertyName;

        if (array_key_exists($cacheKey, self::$propertyTagCache)) {
            return self::$propertyTagCache[$cacheKey];
        }

        return self::$propertyTagCache[$cacheKey] = self::resolvePropertyTag($classReflection, $propertyName);
    }

    /**
     * Resolve whether the class, its ancestors or its mixins declare the property.
     */
    private static function resolvePropertyTag(ClassReflection $classReflection, string $propertyName): bool
    {
        if (array_key_exists($propertyName, $classReflection->getPropertyTags())) {
            return true;
        }

        foreach ($classReflection->getAncestors() as $ancestor) {
            if (array_key_exists($propertyName, $ancestor->getPropertyTags())) {
                return true;
            }
        }

        // @phpstan-ignore phpstanApi.constructor (Reuses PHPStan's mixin lookup, which also checks traits and parents.), phpstanApi.method (Same mixin lookup.)
        return (new MixinPropertiesClassReflectionExtension([$classReflection->getName()]))
            ->hasProperty($classReflection, $propertyName);
    }

    /**
     * Determine whether the given class or its ancestors declare the method with an `@method` tag, or a mixin has it.
     */
    public static function hasMethodTag(ClassReflection $classReflection, string $methodName): bool
    {
        // Hypervel: keyed by the specialization, because a generic class's mixin can depend on its template types.
        $cacheKey = $classReflection->getCacheKey() . '-' . $methodName;

        if (array_key_exists($cacheKey, self::$methodTagCache)) {
            return self::$methodTagCache[$cacheKey];
        }

        return self::$methodTagCache[$cacheKey] = self::resolveMethodTag($classReflection, $methodName);
    }

    /**
     * Resolve whether the class, its ancestors or its mixins declare the method.
     */
    private static function resolveMethodTag(ClassReflection $classReflection, string $methodName): bool
    {
        if (array_key_exists($methodName, $classReflection->getMethodTags())) {
            return true;
        }

        foreach ($classReflection->getAncestors() as $ancestor) {
            if (array_key_exists($methodName, $ancestor->getMethodTags())) {
                return true;
            }
        }

        // @phpstan-ignore phpstanApi.constructor (Reuses PHPStan's mixin lookup, which also checks traits and parents.), phpstanApi.method (Same mixin lookup.)
        return (new MixinMethodsClassReflectionExtension([$classReflection->getName()]))
            ->hasMethod($classReflection, $methodName);
    }
}
