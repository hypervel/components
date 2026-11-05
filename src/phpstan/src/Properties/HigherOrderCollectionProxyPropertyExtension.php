<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Properties;

use Hypervel\PHPStan\Support\HigherOrderCollectionProxyHelper;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\PropertiesClassReflectionExtension;
use PHPStan\Reflection\PropertyReflection;

#[AutowiredService]
final class HigherOrderCollectionProxyPropertyExtension implements PropertiesClassReflectionExtension
{
    /**
     * Create a new property extension instance.
     */
    public function __construct(private HigherOrderCollectionProxyHelper $higherOrderCollectionProxyHelper)
    {
    }

    /**
     * Determine whether the proxy's target has the property.
     */
    public function hasProperty(ClassReflection $classReflection, string $propertyName): bool
    {
        return $this->higherOrderCollectionProxyHelper->hasPropertyOrMethod($classReflection, $propertyName, 'property');
    }

    /**
     * Get the property, typed as what the proxied collection method returns.
     */
    public function getProperty(ClassReflection $classReflection, string $propertyName): PropertyReflection
    {
        return $this->higherOrderCollectionProxyHelper->getProperty($classReflection, $propertyName);
    }
}
