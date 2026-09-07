<?php

declare(strict_types=1);

namespace Hypervel\Data\Support\Factories;

use Hypervel\Data\Data;
use Hypervel\Data\Dto;
use Hypervel\Data\Resource;
use Hypervel\Data\Support\DataAttributesCollection;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionParameter;
use ReflectionProperty;

class DataAttributesCollectionFactory
{
    /**
     * Build the attribute recipes for a class and its data parents.
     *
     * @param ReflectionClass<object> $reflectionClass
     */
    public static function buildFromReflectionClass(ReflectionClass $reflectionClass): DataAttributesCollection
    {
        $attributeGroups = [
            $reflectionClass->getAttributes(),
        ];

        while ($parent = static::findParentReflectionClass($reflectionClass)) {
            $attributeGroups[] = $parent->getAttributes();

            $reflectionClass = $parent;
        }

        return new DataAttributesCollection(
            static::mapAttributesIntoGroups(array_merge(...$attributeGroups)),
        );
    }

    /**
     * Build the attribute recipes for a property.
     */
    public static function buildFromReflectionProperty(ReflectionProperty $reflectionProperty): DataAttributesCollection
    {
        return new DataAttributesCollection(
            static::mapAttributesIntoGroups($reflectionProperty->getAttributes()),
        );
    }

    /**
     * Build the attribute recipes for a parameter.
     */
    public static function buildFromReflectionParameter(ReflectionParameter $reflectionParameter): DataAttributesCollection
    {
        return new DataAttributesCollection(
            static::mapAttributesIntoGroups($reflectionParameter->getAttributes()),
        );
    }

    /**
     * Find the next parent whose attributes belong to the data declaration.
     *
     * @param ReflectionClass<object> $reflectionClass
     * @return null|ReflectionClass<object>
     */
    protected static function findParentReflectionClass(ReflectionClass $reflectionClass): ?ReflectionClass
    {
        $parent = $reflectionClass->getParentClass();

        if ($parent === false) {
            return null;
        }

        if (in_array($parent->name, [Data::class, Dto::class, Resource::class], true)) {
            return null;
        }

        return $parent;
    }

    /**
     * Group attribute recipes by their concrete type, parents, and interfaces.
     *
     * @param list<ReflectionAttribute<object>> $reflectionAttributes
     * @return array<class-string, non-empty-list<ReflectionAttribute<object>>>
     */
    protected static function mapAttributesIntoGroups(array $reflectionAttributes): array
    {
        $attributes = [];

        foreach ($reflectionAttributes as $reflectionAttribute) {
            if (! class_exists($reflectionAttribute->getName())) {
                continue;
            }

            $attributes[$reflectionAttribute->getName()][] = $reflectionAttribute;

            foreach (class_implements($reflectionAttribute->getName()) ?: [] as $interface) {
                $attributes[$interface][] = $reflectionAttribute;
            }

            foreach (class_parents($reflectionAttribute->getName()) ?: [] as $parent) {
                $attributes[$parent][] = $reflectionAttribute;
            }
        }

        return $attributes;
    }
}
