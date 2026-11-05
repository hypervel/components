<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Methods;

use Hypervel\PHPStan\Support\HigherOrderCollectionProxyHelper;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\MethodsClassReflectionExtension;

#[AutowiredService]
final class HigherOrderCollectionProxyExtension implements MethodsClassReflectionExtension
{
    /**
     * Create a new extension instance.
     */
    public function __construct(private HigherOrderCollectionProxyHelper $higherOrderCollectionProxyHelper)
    {
    }

    /**
     * Determine whether the proxy's target has the method.
     */
    public function hasMethod(ClassReflection $classReflection, string $methodName): bool
    {
        return $this->higherOrderCollectionProxyHelper->hasPropertyOrMethod($classReflection, $methodName, 'method');
    }

    /**
     * Get the method, returning what the proxied collection method returns.
     */
    public function getMethod(ClassReflection $classReflection, string $methodName): MethodReflection
    {
        return $this->higherOrderCollectionProxyHelper->getMethod($classReflection, $methodName);
    }
}
