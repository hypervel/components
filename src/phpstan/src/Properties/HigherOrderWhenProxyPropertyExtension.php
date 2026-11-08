<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Properties;

use Hypervel\PHPStan\Support\HigherOrderWhenProxyHelper;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\PropertiesClassReflectionExtension;
use PHPStan\Reflection\PropertyReflection;

#[AutowiredService]
class HigherOrderWhenProxyPropertyExtension implements PropertiesClassReflectionExtension
{
    /**
     * Create a new property extension instance.
     */
    public function __construct(private HigherOrderWhenProxyHelper $higherOrderWhenProxyHelper)
    {
    }

    /**
     * Determine whether the proxy's target has the property.
     */
    public function hasProperty(ClassReflection $classReflection, string $propertyName): bool
    {
        return $this->higherOrderWhenProxyHelper->hasPropertyOrMethod($classReflection, $propertyName, 'property');
    }

    /**
     * Get the property, typed as what the proxy returns for it.
     */
    public function getProperty(ClassReflection $classReflection, string $propertyName): PropertyReflection
    {
        return $this->higherOrderWhenProxyHelper->getProperty($classReflection, $propertyName);
    }
}
