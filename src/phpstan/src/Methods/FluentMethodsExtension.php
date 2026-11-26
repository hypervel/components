<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Methods;

use Hypervel\PHPStan\Reflection\DynamicMethodReflection;
use Hypervel\PHPStan\Reflection\DynamicWhereParameterReflection;
use Hypervel\PHPStan\Reflection\ReflectionHelper;
use Hypervel\Support\Fluent;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\FunctionVariant;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\MethodsClassReflectionExtension;
use PHPStan\Type\Generic\TemplateTypeMap;
use PHPStan\Type\StaticType;

use function str_starts_with;

#[AutowiredService]
class FluentMethodsExtension implements MethodsClassReflectionExtension
{
    /**
     * Create a fluent methods extension.
     */
    public function __construct(private MacroMethodsClassReflectionExtension $macros)
    {
    }

    /**
     * Determine whether the method is a dynamic attribute setter on a fluent object.
     */
    public function hasMethod(ClassReflection $classReflection, string $methodName): bool
    {
        // Declared methods and registered macros keep their own signatures.
        return $classReflection->is(Fluent::class)
            && ! str_starts_with($methodName, '__')
            && ! ReflectionHelper::hasMethodTag($classReflection, $methodName)
            && ! $this->macros->hasMacro($classReflection, $methodName);
    }

    /**
     * Get the reflection of the dynamic attribute setter.
     */
    public function getMethod(ClassReflection $classReflection, string $methodName): MethodReflection
    {
        return new DynamicMethodReflection($classReflection, $methodName, [
            new FunctionVariant(
                TemplateTypeMap::createEmpty(),
                null,
                [new DynamicWhereParameterReflection('value', optional: true)],
                false,
                new StaticType($classReflection),
            ),
        ]);
    }
}
