<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Methods;

use Hypervel\Http\RedirectResponse;
use Hypervel\PHPStan\Reflection\DynamicWhereMethodReflection;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\MethodsClassReflectionExtension;

use function str_starts_with;

#[AutowiredService]
class RedirectResponseMethodsClassReflectionExtension implements MethodsClassReflectionExtension
{
    /**
     * Determine whether the method is a dynamic with*() call on a redirect response.
     */
    public function hasMethod(ClassReflection $classReflection, string $methodName): bool
    {
        // Upstream matches only the exact class; subclasses inherit its __call().
        if (! $classReflection->is(RedirectResponse::class)) {
            return false;
        }

        if (! str_starts_with($methodName, 'with')) {
            return false;
        }

        return true;
    }

    /**
     * Get the reflection of the dynamic with*() method.
     */
    public function getMethod(
        ClassReflection $classReflection,
        string $methodName,
    ): MethodReflection {
        return new DynamicWhereMethodReflection($classReflection, $methodName);
    }
}
