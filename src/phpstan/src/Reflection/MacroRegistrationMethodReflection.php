<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Reflection;

use PHPStan\Reflection\ExtendedParametersAcceptor;
use PHPStan\Reflection\FunctionVariant;
use PHPStan\Reflection\ParameterReflection;
use PHPStan\Reflection\ParametersAcceptor;

use function array_map;

/**
 * Describe an Eloquent builder's macro() or mixin(), which both its __call() and __callStatic() accept.
 *
 * The query's closure binding is not copied, because a local macro runs with its own $this.
 * MacroRegistrationExtension types the call forms that differ.
 */
class MacroRegistrationMethodReflection extends ForwardedMethodReflection
{
    /**
     * Determine whether the method is static.
     */
    public function isStatic(): bool
    {
        return true;
    }

    /**
     * Return the variants without the query's closure binding.
     *
     * @return list<ParametersAcceptor>
     */
    public function getVariants(): array
    {
        return array_map(
            static fn (ParametersAcceptor $variant): FunctionVariant => new FunctionVariant(
                $variant->getTemplateTypeMap(),
                $variant->getResolvedTemplateTypeMap(),
                array_map(
                    static fn (ParameterReflection $parameter): ParameterReflection => new ProxiedParameterReflection($parameter),
                    $variant->getParameters(),
                ),
                $variant->isVariadic(),
                $variant->getReturnType(),
                $variant instanceof ExtendedParametersAcceptor ? $variant->getCallSiteVarianceMap() : null,
            ),
            $this->forwardedMethod->getVariants(),
        );
    }
}
