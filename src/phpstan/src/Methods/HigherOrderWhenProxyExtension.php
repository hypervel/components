<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Methods;

use Hypervel\PHPStan\Support\HigherOrderWhenProxyHelper;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\MethodsClassReflectionExtension;

#[AutowiredService]
class HigherOrderWhenProxyExtension implements MethodsClassReflectionExtension
{
    /**
     * Create a new extension instance.
     */
    public function __construct(private HigherOrderWhenProxyHelper $higherOrderWhenProxyHelper)
    {
    }

    /**
     * Determine whether the proxy's target has the method.
     */
    public function hasMethod(ClassReflection $classReflection, string $methodName): bool
    {
        return $this->higherOrderWhenProxyHelper->hasPropertyOrMethod($classReflection, $methodName, 'method');
    }

    /**
     * Get the method, returning what the proxy returns for it.
     */
    public function getMethod(ClassReflection $classReflection, string $methodName): MethodReflection
    {
        return $this->higherOrderWhenProxyHelper->getMethod($classReflection, $methodName);
    }
}
