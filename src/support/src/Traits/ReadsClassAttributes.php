<?php

declare(strict_types=1);

namespace Hypervel\Support\Traits;

use Exception;
use ReflectionClass;

trait ReadsClassAttributes
{
    /**
     * Get a configuration value from an attribute, falling back to a property.
     */
    protected function getAttributeValue(object $target, string $attributeClass, ?string $property = null, mixed $default = null): mixed
    {
        $reflection = new ReflectionClass($target);

        $defaultProperties = $reflection->getDefaultProperties();

        if ($property !== null
            && isset($target->{$property})
            && $target->{$property} !== ($defaultProperties[$property] ?? null)
        ) {
            return $target->{$property};
        }

        if ($instance = $this->getAttributeInstance($target, $attributeClass, $attributeDeclaringClass)) {
            if ($this->propertyOverridesAttribute($target, $reflection, $property, $attributeDeclaringClass)) {
                return $target->{$property};
            }

            return $this->extractAttributeValue($instance);
        }

        return $property !== null
            ? ($target->{$property} ?? $default)
            : $default;
    }

    /**
     * Extract the value from an attribute instance.
     */
    protected function extractAttributeValue(object $instance): mixed
    {
        $properties = get_object_vars($instance);

        return $properties === [] ? true : reset($properties);
    }

    /**
     * Get an instance of the given attribute class from the target class or its parents.
     */
    protected function getAttributeInstance(object $target, string $attributeClass, ?ReflectionClass &$declaringClass = null): ?object
    {
        $reflection = new ReflectionClass($target);

        try {
            do {
                $attributes = $reflection->getAttributes($attributeClass);

                if (count($attributes) > 0) {
                    $declaringClass = $reflection;

                    return $attributes[0]->newInstance();
                }

                foreach ($reflection->getTraits() as $trait) {
                    $attributes = $trait->getAttributes($attributeClass);

                    if (count($attributes) > 0) {
                        $declaringClass = $reflection;

                        return $attributes[0]->newInstance();
                    }
                }
            } while ($reflection = $reflection->getParentClass());
        } catch (Exception) {
        }

        return null;
    }

    /**
     * Determine if a property declared on a child class overrides an inherited attribute.
     */
    protected function propertyOverridesAttribute(object $target, ReflectionClass $reflection, ?string $property, ReflectionClass $attributeDeclaringClass): bool
    {
        if ($property === null || ! $reflection->hasProperty($property)) {
            return false;
        }

        $propertyReflection = $reflection->getProperty($property);

        return $propertyReflection->isPublic()
            && $propertyReflection->isInitialized($target)
            && $propertyReflection->getDeclaringClass()->isSubclassOf($attributeDeclaringClass->getName());
    }
}
