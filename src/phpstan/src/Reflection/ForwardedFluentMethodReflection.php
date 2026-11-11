<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Reflection;

use PHPStan\Reflection\ExtendedParametersAcceptor;
use PHPStan\Reflection\FunctionVariant;
use PHPStan\Reflection\ParametersAcceptor;
use PHPStan\Type\ThisType;

/**
 * Describe a forwarded fluent method whose runtime result is the receiving object.
 */
class ForwardedFluentMethodReflection extends ForwardedMethodReflection
{
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
                new ThisType($this->declaringClass),
                $variant instanceof ExtendedParametersAcceptor ? $variant->getCallSiteVarianceMap() : null,
            ),
            $this->forwardedMethod->getVariants(),
        );
    }
}
