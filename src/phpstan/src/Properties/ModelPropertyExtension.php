<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Properties;

use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\PropertiesClassReflectionExtension;
use PHPStan\Reflection\PropertyReflection;

/** @internal */
#[AutowiredService]
class ModelPropertyExtension implements PropertiesClassReflectionExtension
{
    /**
     * Create a new model property extension instance.
     */
    public function __construct(
        private ModelPropertyHelper $modelPropertyHelper,
        private ModelRelationsExtension $modelRelationsExtension,
    ) {
    }

    /**
     * Determine whether the model has a database property, unless an accessor or mutator handles it.
     *
     * A property the schema facts cannot establish is left to a declared relation, which Eloquent
     * reads when no attribute has that name.
     */
    public function hasProperty(ClassReflection $classReflection, string $propertyName): bool
    {
        if ($this->modelPropertyHelper->hasAccessor($classReflection, $propertyName)) {
            return false;
        }

        $hasProperty = $this->modelPropertyHelper->hasDatabaseProperty($classReflection, $propertyName);

        return $hasProperty->yes()
            || ($hasProperty->maybe() && ! $this->modelRelationsExtension->hasProperty($classReflection, $propertyName));
    }

    /**
     * Get the database property.
     */
    public function getProperty(ClassReflection $classReflection, string $propertyName): PropertyReflection
    {
        return $this->modelPropertyHelper->getDatabaseProperty($classReflection, $propertyName);
    }
}
