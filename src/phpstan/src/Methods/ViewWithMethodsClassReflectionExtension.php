<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Methods;

use Hypervel\PHPStan\Reflection\DynamicWhereMethodReflection;
use Hypervel\View\View;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\MethodsClassReflectionExtension;

use function str_starts_with;

#[AutowiredService]
class ViewWithMethodsClassReflectionExtension implements MethodsClassReflectionExtension
{
    /**
     * Determine whether the method is a dynamic with*() call on a view.
     */
    public function hasMethod(ClassReflection $classReflection, string $methodName): bool
    {
        // Upstream matches the exact view class and the view contract. Subclasses inherit the
        // view's __call(), but the contract does not promise dynamic with*() methods.
        if (! $classReflection->is(View::class)) {
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
