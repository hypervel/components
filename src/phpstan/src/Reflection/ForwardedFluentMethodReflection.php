<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Reflection;

use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ExtendedParametersAcceptor;
use PHPStan\Reflection\FunctionVariant;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\ParametersAcceptor;
use PHPStan\Type\ThisType;
use PHPStan\Type\TypeCombinator;

/**
 * Describe a forwarded fluent method whose runtime result is the receiving object.
 */
class ForwardedFluentMethodReflection extends ForwardedMethodReflection
{
    /**
     * Create a forwarded fluent method reflection.
     *
     * @param bool $mayReturnForwardedResult whether the forwarded method can also return another object,
     *                                       which the receiver then returns as it is
     */
    public function __construct(
        ClassReflection $declaringClass,
        MethodReflection $forwardedMethod,
        private readonly bool $mayReturnForwardedResult = false,
    ) {
        parent::__construct($declaringClass, $forwardedMethod);
    }

    /**
     * Return the callable variants with the receiving object as their result.
     *
     * @return list<ParametersAcceptor>
     */
    public function getVariants(): array
    {
        return array_map(
            fn (ParametersAcceptor $variant): FunctionVariant => new FunctionVariant(
                $variant->getTemplateTypeMap(),
                $variant->getResolvedTemplateTypeMap(),
                ProxiedParameterReflection::fromTargetParameters($variant->getParameters()),
                $variant->isVariadic(),
                $this->mayReturnForwardedResult
                    ? TypeCombinator::union(new ThisType($this->declaringClass), $variant->getReturnType())
                    : new ThisType($this->declaringClass),
                $variant instanceof ExtendedParametersAcceptor ? $variant->getCallSiteVarianceMap() : null,
            ),
            $this->forwardedMethod->getVariants(),
        );
    }
}
