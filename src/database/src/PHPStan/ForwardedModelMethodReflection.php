<?php

declare(strict_types=1);

namespace Hypervel\Database\PHPStan;

use PHPStan\Reflection\ClassMemberReflection;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\ParametersAcceptor;
use PHPStan\TrinaryLogic;
use PHPStan\Type\Type;

/**
 * Describe a builder method forwarded through an Eloquent model.
 */
class ForwardedModelMethodReflection implements MethodReflection
{
    /**
     * Create a forwarded model method reflection.
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
     * Return the forwarded builder method variants.
     *
     * @return list<ParametersAcceptor>
     */
    public function getVariants(): array
    {
        return $this->forwardedMethod->getVariants();
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
