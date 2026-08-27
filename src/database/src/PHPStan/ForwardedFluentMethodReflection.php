<?php

declare(strict_types=1);

namespace Hypervel\Database\PHPStan;

use PHPStan\Reflection\ClassMemberReflection;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ExtendedParametersAcceptor;
use PHPStan\Reflection\FunctionVariant;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\ParametersAcceptor;
use PHPStan\TrinaryLogic;
use PHPStan\Type\ThisType;
use PHPStan\Type\Type;

/**
 * Describe a forwarded fluent method whose runtime result is the receiving object.
 */
class ForwardedFluentMethodReflection implements MethodReflection
{
    /**
     * Create a forwarded fluent method reflection.
     */
    public function __construct(
        private readonly ClassReflection $declaringClass,
        private readonly MethodReflection $forwardedMethod,
    ) {
    }

    /**
     * Return the method name.
     */
    public function getName(): string
    {
        return $this->forwardedMethod->getName();
    }

    /**
     * Return the declaring class.
     */
    public function getDeclaringClass(): ClassReflection
    {
        return $this->declaringClass;
    }

    /**
     * Determine whether the method is static.
     */
    public function isStatic(): bool
    {
        return $this->forwardedMethod->isStatic();
    }

    /**
     * Determine whether the method is private.
     */
    public function isPrivate(): bool
    {
        return $this->forwardedMethod->isPrivate();
    }

    /**
     * Determine whether the method is public.
     */
    public function isPublic(): bool
    {
        return $this->forwardedMethod->isPublic();
    }

    /**
     * Return the method docblock.
     */
    public function getDocComment(): ?string
    {
        return $this->forwardedMethod->getDocComment();
    }

    /**
     * Return the method prototype.
     */
    public function getPrototype(): ClassMemberReflection
    {
        return $this;
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
                $variant->getParameters(),
                $variant->isVariadic(),
                new ThisType($this->declaringClass),
                $variant instanceof ExtendedParametersAcceptor ? $variant->getCallSiteVarianceMap() : null,
            ),
            $this->forwardedMethod->getVariants(),
        );
    }

    /**
     * Determine whether the method is deprecated.
     */
    public function isDeprecated(): TrinaryLogic
    {
        return $this->forwardedMethod->isDeprecated();
    }

    /**
     * Return the deprecation description.
     */
    public function getDeprecatedDescription(): ?string
    {
        return $this->forwardedMethod->getDeprecatedDescription();
    }

    /**
     * Determine whether the method is final.
     */
    public function isFinal(): TrinaryLogic
    {
        return $this->forwardedMethod->isFinal();
    }

    /**
     * Determine whether the method is internal.
     */
    public function isInternal(): TrinaryLogic
    {
        return $this->forwardedMethod->isInternal();
    }

    /**
     * Return the declared throw type.
     */
    public function getThrowType(): ?Type
    {
        return $this->forwardedMethod->getThrowType();
    }

    /**
     * Determine whether the method has side effects.
     */
    public function hasSideEffects(): TrinaryLogic
    {
        return $this->forwardedMethod->hasSideEffects();
    }
}
