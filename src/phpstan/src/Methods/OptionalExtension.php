<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Methods;

use Hypervel\PHPStan\Reflection\ProxiedMethodReflection;
use Hypervel\Support\Optional;
use PHPStan\Analyser\OutOfClassScope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\MethodsClassReflectionExtension;
use PHPStan\Type\MixedType;
use PHPStan\Type\NullType;
use PHPStan\Type\ObjectWithoutClassType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;

/**
 * Passes an Optional's method calls to the object it holds. The call returns null when the
 * Optional may hold something else, and only null when it never holds an object.
 */
#[AutowiredService]
final class OptionalExtension implements MethodsClassReflectionExtension
{
    /**
     * Determine whether the object the Optional holds has the public method.
     */
    public function hasMethod(ClassReflection $classReflection, string $methodName): bool
    {
        $targetType = $this->getTargetType($classReflection);

        // Optional::__call() runs a macro before the held object's method, so the macro extension
        // resolves it. The Optional calls the held object's method from outside the object's class.
        return $targetType !== null
            && ! Optional::hasMacro($methodName)
            && $targetType->hasMethod($methodName)->yes()
            && $targetType->getMethod($methodName, new OutOfClassScope)->isPublic();
    }

    /**
     * Get the held object's method, returning what the Optional returns for it.
     */
    public function getMethod(ClassReflection $classReflection, string $methodName): MethodReflection
    {
        // hasMethod() accepts only Optionals with a value type.
        /** @var Type $targetType */
        $targetType = $this->getTargetType($classReflection);

        /** @var Type $valueType */
        $valueType = $classReflection->getActiveTemplateTypeMap()->getType('TValue');
        $isObject = $valueType->isObject();

        return new ProxiedMethodReflection(
            $classReflection,
            $targetType->getMethod($methodName, new OutOfClassScope),
            static fn (Type $returnType): Type => match (true) {
                $isObject->no() => new NullType,
                $isObject->yes() => $returnType,
                default => TypeCombinator::addNull($returnType),
            },
        );
    }

    /**
     * Get the type of the object the Optional may hold.
     */
    private function getTargetType(ClassReflection $classReflection): ?Type
    {
        if ($classReflection->getName() !== Optional::class) {
            return null;
        }

        $valueType = $classReflection->getActiveTemplateTypeMap()->getType('TValue');

        if ($valueType === null) {
            return null;
        }

        // A value that is never an object accepts any call, as the Optional skips it.
        if ($valueType->isObject()->no()) {
            return new MixedType;
        }

        $objectType = TypeCombinator::intersect($valueType, new ObjectWithoutClassType);

        // An object of unknown class may have any method, like a mixed value.
        return $objectType->getObjectClassNames() === [] ? new MixedType : $objectType;
    }
}
