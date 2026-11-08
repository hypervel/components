<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Properties;

use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\PropertiesClassReflectionExtension;
use PHPStan\Reflection\PropertyReflection;

/** @internal */
#[AutowiredService]
final class ModelAccessorExtension implements PropertiesClassReflectionExtension
{
    /**
     * Create a new model accessor extension instance.
     */
    public function __construct(
        private ModelPropertyHelper $modelPropertyHelper,
    ) {
    }

    /**
     * Determine whether an accessor or mutator handles the property.
     */
    public function hasProperty(ClassReflection $classReflection, string $propertyName): bool
    {
        return $this->modelPropertyHelper->hasAccessor($classReflection, $propertyName);
    }

    /**
     * Get the accessor property.
     */
    public function getProperty(ClassReflection $classReflection, string $propertyName): PropertyReflection
    {
        return $this->modelPropertyHelper->getAccessor($classReflection, $propertyName);
    }
}
