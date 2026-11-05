<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Support;

use Hypervel\PHPStan\Reflection\ProxiedMethodReflection;
use Hypervel\PHPStan\Reflection\ProxiedPropertyReflection;
use Hypervel\Support\HigherOrderWhenProxy;
use PHPStan\Analyser\OutOfClassScope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\PropertyReflection;
use PHPStan\Type\BooleanType;
use PHPStan\Type\Constant\ConstantBooleanType;
use PHPStan\Type\Generic\GenericObjectType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;

/**
 * Types the properties and methods a HigherOrderWhenProxy passes to its target. A proxy
 * without a condition takes it from the member and returns itself. A proxy with a condition
 * returns the member's value when the condition holds, and the target otherwise.
 */
#[AutowiredService]
final class HigherOrderWhenProxyHelper
{
    /**
     * Determine whether the proxy's target has the method or property.
     *
     * @param 'method'|'property' $propertyOrMethod
     */
    public function hasPropertyOrMethod(ClassReflection $classReflection, string $name, string $propertyOrMethod): bool
    {
        $targetType = $this->getTargetType($classReflection);

        if ($targetType === null) {
            return false;
        }

        // The proxy reaches only public members, since it runs outside the target's class.
        return $propertyOrMethod === 'method'
            ? $targetType->hasMethod($name)->yes() && $targetType->getMethod($name, new OutOfClassScope)->isPublic()
            : $targetType->hasInstanceProperty($name)->yes() && $targetType->getInstanceProperty($name, new OutOfClassScope)->isPublic();
    }

    /**
     * Get the property read through the proxy.
     */
    public function getProperty(ClassReflection $classReflection, string $name): PropertyReflection
    {
        // hasProperty() accepts only proxies with a target.
        /** @var Type $targetType */
        $targetType = $this->getTargetType($classReflection);
        $targetProperty = $targetType->getInstanceProperty($name, new OutOfClassScope);

        return new ProxiedPropertyReflection($classReflection, $this->getReturnType($classReflection, $targetProperty->getReadableType()), $targetProperty);
    }

    /**
     * Get the method called through the proxy.
     */
    public function getMethod(ClassReflection $classReflection, string $name): MethodReflection
    {
        // hasMethod() accepts only proxies with a target.
        /** @var Type $targetType */
        $targetType = $this->getTargetType($classReflection);

        return new ProxiedMethodReflection(
            $classReflection,
            $targetType->getMethod($name, new OutOfClassScope),
            fn (Type $returnType): Type => $this->getReturnType($classReflection, $returnType),
        );
    }

    /**
     * Get what the proxy returns for a member of the given type.
     */
    private function getReturnType(ClassReflection $classReflection, Type $memberType): Type
    {
        $templateTypeMap = $classReflection->getActiveTemplateTypeMap();

        // getTargetType() accepts only proxies with a target.
        /** @var Type $targetType */
        $targetType = $templateTypeMap->getType('TTarget');
        $hasCondition = $templateTypeMap->getType('THasCondition') ?? new BooleanType;
        $types = [];

        if (! $hasCondition->isTrue()->yes()) {
            $types[] = new GenericObjectType(HigherOrderWhenProxy::class, [$targetType, new ConstantBooleanType(true)]);
        }

        if (! $hasCondition->isFalse()->yes()) {
            $types[] = $memberType;
            $types[] = $targetType;
        }

        return TypeCombinator::union(...$types);
    }

    /**
     * Get the object the proxy passes members to.
     */
    private function getTargetType(ClassReflection $classReflection): ?Type
    {
        if ($classReflection->getName() !== HigherOrderWhenProxy::class) {
            return null;
        }

        $targetType = $classReflection->getActiveTemplateTypeMap()->getType('TTarget');

        return $targetType !== null && $targetType->isObject()->yes() ? $targetType : null;
    }
}
