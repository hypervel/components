<?php

declare(strict_types=1);

namespace Hypervel\Head\PHPStan;

use PHPStan\Reflection\ClassMemberReflection;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ExtendedParametersAcceptor;
use PHPStan\Reflection\FunctionVariant;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\ParametersAcceptor;
use PHPStan\TrinaryLogic;
use PHPStan\Type\Type;

/**
 * Describe the routing macro using its native signature and receiver-specific result.
 */
class WithHeadMethodReflection implements MethodReflection
{
    /**
     * Create a routing macro method reflection.
     */
    public function __construct(
        private readonly ClassReflection $declaringClass,
        private readonly MethodReflection $signature,
        private readonly Type $returnType,
        private readonly bool $isStatic,
    ) {
    }

    /**
     * Return the method name.
     */
    public function getName(): string
    {
        return $this->signature->getName();
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
        return $this->isStatic;
    }

    /**
     * Determine whether the method is private.
     */
    public function isPrivate(): bool
    {
        return $this->signature->isPrivate();
    }

    /**
     * Determine whether the method is public.
     */
    public function isPublic(): bool
    {
        return $this->signature->isPublic();
    }

    /**
     * Return the method docblock.
     */
    public function getDocComment(): ?string
    {
        return $this->signature->getDocComment();
    }

    /**
     * Return the method prototype.
     */
    public function getPrototype(): ClassMemberReflection
    {
        return $this;
    }

    /**
     * Return the callable variants with the routing result type.
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
                $this->returnType,
                $variant instanceof ExtendedParametersAcceptor ? $variant->getCallSiteVarianceMap() : null,
            ),
            $this->signature->getVariants(),
        );
    }

    /**
     * Determine whether the method is deprecated.
     */
    public function isDeprecated(): TrinaryLogic
    {
        return $this->signature->isDeprecated();
    }

    /**
     * Return the deprecation description.
     */
    public function getDeprecatedDescription(): ?string
    {
        return $this->signature->getDeprecatedDescription();
    }

    /**
     * Determine whether the method is final.
     */
    public function isFinal(): TrinaryLogic
    {
        return $this->signature->isFinal();
    }

    /**
     * Determine whether the method is internal.
     */
    public function isInternal(): TrinaryLogic
    {
        return $this->signature->isInternal();
    }

    /**
     * Return the declared throw type.
     */
    public function getThrowType(): ?Type
    {
        return $this->signature->getThrowType();
    }

    /**
     * Determine whether the method has side effects.
     */
    public function hasSideEffects(): TrinaryLogic
    {
        return $this->signature->hasSideEffects();
    }
}
