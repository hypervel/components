<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Methods;

use Hypervel\PHPStan\Reflection\ProxiedMethodReflection;
use Hypervel\Support\HigherOrderTapProxy;
use PHPStan\Analyser\OutOfClassScope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\MethodsClassReflectionExtension;
use PHPStan\Type\Type;

#[AutowiredService]
class HigherOrderTapProxyExtension implements MethodsClassReflectionExtension
{
    /**
     * Determine whether the tapped object has the public method.
     */
    public function hasMethod(ClassReflection $classReflection, string $methodName): bool
    {
        $targetType = $this->getTargetType($classReflection);

        // The proxy calls the method from outside the tapped object's class.
        return $targetType !== null
            && $targetType->hasMethod($methodName)->yes()
            && $targetType->getMethod($methodName, new OutOfClassScope)->isPublic();
    }

    /**
     * Get the tapped object's method, returning the tapped object.
     */
    public function getMethod(ClassReflection $classReflection, string $methodName): MethodReflection
    {
        // hasMethod() accepts only proxies with a tapped object.
        /** @var Type $targetType */
        $targetType = $this->getTargetType($classReflection);

        return new ProxiedMethodReflection(
            $classReflection,
            $targetType->getMethod($methodName, new OutOfClassScope),
            static fn (): Type => $targetType,
        );
    }

    /**
     * Get the tapped object's type.
     */
    private function getTargetType(ClassReflection $classReflection): ?Type
    {
        if ($classReflection->getName() !== HigherOrderTapProxy::class) {
            return null;
        }

        $targetType = $classReflection->getActiveTemplateTypeMap()->getType('TTarget');

        return $targetType !== null && $targetType->getObjectClassReflections() !== [] ? $targetType : null;
    }
}
