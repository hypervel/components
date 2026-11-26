<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Methods;

use Hypervel\Http\RedirectResponse;
use Hypervel\Mail\Mailable;
use Hypervel\PHPStan\Reflection\DynamicMethodReflection;
use Hypervel\PHPStan\Reflection\DynamicWhereParameterReflection;
use Hypervel\PHPStan\Reflection\ReflectionHelper;
use Hypervel\View\View;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\FunctionVariant;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\MethodsClassReflectionExtension;
use PHPStan\Type\Generic\TemplateTypeMap;
use PHPStan\Type\StaticType;

use function array_any;
use function str_starts_with;

#[AutowiredService]
class DynamicWithMethodsExtension implements MethodsClassReflectionExtension
{
    /**
     * The classes whose __call() passes a with*() call's value to with().
     *
     * Hypervel: upstream also matches the view contract, which does not promise dynamic with*() methods.
     */
    private const array CLASSES = [
        Mailable::class,
        RedirectResponse::class,
        View::class,
    ];

    /**
     * Create a dynamic with*() methods extension.
     */
    public function __construct(private MacroMethodsClassReflectionExtension $macros)
    {
    }

    /**
     * Determine whether the method is a dynamic with*() call.
     */
    public function hasMethod(ClassReflection $classReflection, string $methodName): bool
    {
        // A registered macro runs before __call() reaches with*(), so it keeps its own signature, as does
        // a subclass's @method tag.
        return str_starts_with($methodName, 'with')
            && array_any(self::CLASSES, static fn (string $className): bool => $classReflection->is($className))
            && ! ReflectionHelper::hasMethodTag($classReflection, $methodName)
            && ! $this->macros->hasMacro($classReflection, $methodName);
    }

    /**
     * Get the reflection of the dynamic with*() method.
     */
    public function getMethod(
        ClassReflection $classReflection,
        string $methodName,
    ): MethodReflection {
        return new DynamicMethodReflection($classReflection, $methodName, [
            new FunctionVariant(
                TemplateTypeMap::createEmpty(),
                null,
                [new DynamicWhereParameterReflection],
                false,
                new StaticType($classReflection),
            ),
        ]);
    }
}
