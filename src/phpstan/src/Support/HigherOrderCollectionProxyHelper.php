<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Support;

use Hypervel\PHPStan\Reflection\ProxiedMethodReflection;
use Hypervel\PHPStan\Reflection\ProxiedPropertyReflection;
use Hypervel\Support\HigherOrderCollectionProxy;
use PHPStan\Analyser\OutOfClassScope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\ParametersAcceptorSelector;
use PHPStan\Reflection\PropertyReflection;
use PHPStan\Type\ClosureType;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\Type;

use function count;
use function in_array;

/**
 * Types a higher-order collection proxy as the collection method it names, called with a
 * callback that returns what the proxied member returns, so each result follows that
 * method's own signature.
 *
 * Upstream types each collection method by hand, giving most results integer keys and the
 * base collection class, and supports neither array items nor class-string items.
 */
#[AutowiredService]
class HigherOrderCollectionProxyHelper
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
        if ($propertyOrMethod === 'property') {
            // The proxy reads array items by key.
            return $targetType->isArray()->yes()
                ? $targetType->hasOffsetValueType(new ConstantStringType($name))->yes()
                : $targetType->hasInstanceProperty($name)->yes() && $targetType->getInstanceProperty($name, new OutOfClassScope)->isPublic();
        }

        // The proxy calls the static method of class-string items.
        if ($targetType->isClassString()->yes()) {
            $classType = $targetType->getClassStringObjectType();

            if (! $classType->hasMethod($name)->yes()) {
                return false;
            }

            $method = $classType->getMethod($name, new OutOfClassScope);

            return $method->isStatic() && $method->isPublic();
        }

        return $targetType->hasMethod($name)->yes() && $targetType->getMethod($name, new OutOfClassScope)->isPublic();
    }

    /**
     * Get the property read through the proxy.
     */
    public function getProperty(ClassReflection $classReflection, string $name): PropertyReflection
    {
        // hasProperty() accepts only proxies with a target.
        /** @var Type $targetType */
        $targetType = $this->getTargetType($classReflection);

        if ($targetType->isArray()->yes()) {
            return new ProxiedPropertyReflection(
                $classReflection,
                $this->getReturnType($classReflection, $targetType->getOffsetValueType(new ConstantStringType($name))),
                null,
            );
        }

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

        if ($targetType->isClassString()->yes()) {
            $targetType = $targetType->getClassStringObjectType();
        }

        return new ProxiedMethodReflection(
            $classReflection,
            $targetType->getMethod($name, new OutOfClassScope),
            fn (Type $returnType): Type => $this->getReturnType($classReflection, $returnType),
        );
    }

    /**
     * Get what the proxied collection method returns for a callback returning the given type.
     */
    private function getReturnType(ClassReflection $classReflection, Type $callbackReturnType): Type
    {
        $templateTypeMap = $classReflection->getActiveTemplateTypeMap();

        // getTargetType() accepts only proxies with these templates.
        /** @var Type $methodType */
        $methodType = $templateTypeMap->getType('TMethod');

        /** @var Type $collectionType */
        $collectionType = $templateTypeMap->getType('TCollection');

        return ParametersAcceptorSelector::selectFromTypes(
            [new ClosureType([], $callbackReturnType, false)],
            $collectionType->getMethod($methodType->getConstantStrings()[0]->getValue(), new OutOfClassScope)->getVariants(),
            false,
        )->getReturnType();
    }

    /**
     * Get the type the proxy calls members on: the collection items, or the collection itself
     * for when() and unless(), which pass the collection to the callback.
     */
    private function getTargetType(ClassReflection $classReflection): ?Type
    {
        if ($classReflection->getName() !== HigherOrderCollectionProxy::class) {
            return null;
        }

        $templateTypeMap = $classReflection->getActiveTemplateTypeMap();
        $methodType = $templateTypeMap->getType('TMethod');
        $valueType = $templateTypeMap->getType('TValue');
        $collectionType = $templateTypeMap->getType('TCollection');

        if ($methodType === null || $valueType === null || $collectionType === null || count($methodType->getConstantStrings()) !== 1) {
            return null;
        }

        $method = $methodType->getConstantStrings()[0]->getValue();

        if (! $collectionType->hasMethod($method)->yes()) {
            return null;
        }

        return in_array($method, ['when', 'unless'], true) ? $collectionType : $valueType;
    }
}
