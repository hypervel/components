<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Reflection;

use PHPStan\Reflection\ClassMemberReflection;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\ParametersAcceptor;
use PHPStan\TrinaryLogic;
use PHPStan\Type\Type;

/**
 * Describe a method that another object handles for the receiver.
 *
 * The receiver is the declaring class, so PHPStan binds the receiver's template types in the forwarded signature.
 */
class ForwardedMethodReflection implements MethodReflection
{
    /**
     * Create a forwarded method reflection.
     */
    public function __construct(
        protected readonly ClassReflection $declaringClass,
        protected readonly MethodReflection $forwardedMethod,
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
     * Return the method of the object that handles the call.
     */
    public function getForwardedMethod(): MethodReflection
    {
        return $this->forwardedMethod;
    }

    /**
     * Determine whether the method is static.
     */
    public function isStatic(): bool
    {
        // The receiver's __call() forwards it, so a static method of the other object is not static here:
        // Builder::__callStatic(), for one, runs only the builder's own global macros.
        return false;
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
     * Return the forwarded method variants.
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
