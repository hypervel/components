<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Reflection;

use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ExtendedParametersAcceptor;
use PHPStan\Reflection\FunctionVariant;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\ParametersAcceptor;
use PHPStan\Type\Type;

use function array_map;

/**
 * Describe a method an Eloquent model's __call() or __callStatic() handles, which is callable both ways.
 */
class ForwardedModelMethodReflection extends ForwardedMethodReflection
{
    /**
     * Create a forwarded model method reflection.
     *
     * @param null|Type $returnType replaces the builder method's declared result with what the instance call returns
     */
    public function __construct(
        ClassReflection $declaringClass,
        MethodReflection $forwardedMethod,
        private readonly ?Type $returnType = null,
    ) {
        parent::__construct($declaringClass, $forwardedMethod);
    }

    /**
     * Return the builder method's variants, with the instance call's result when it differs.
     *
     * @return list<ParametersAcceptor>
     */
    public function getVariants(): array
    {
        if ($this->returnType === null) {
            return parent::getVariants();
        }

        return array_map(
            fn (ParametersAcceptor $variant): FunctionVariant => new FunctionVariant(
                $variant->getTemplateTypeMap(),
                $variant->getResolvedTemplateTypeMap(),
                $variant->getParameters(),
                $variant->isVariadic(),
                $this->returnType,
                $variant instanceof ExtendedParametersAcceptor ? $variant->getCallSiteVarianceMap() : null,
            ),
            parent::getVariants(),
        );
    }

    /**
     * Determine whether the method is static.
     */
    public function isStatic(): bool
    {
        return true;
    }

    /**
     * Determine whether the method is private.
     */
    public function isPrivate(): bool
    {
        return false;
    }

    /**
     * Determine whether the method is public.
     */
    public function isPublic(): bool
    {
        return true;
    }
}
