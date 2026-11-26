<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Reflection;

use PHPStan\Reflection\ClassMemberReflection;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\ParametersAcceptor;
use PHPStan\TrinaryLogic;
use PHPStan\Type\Type;

class DynamicMethodReflection implements MethodReflection
{
    /**
     * Create a new dynamic method reflection.
     *
     * @param list<ParametersAcceptor> $variants
     */
    public function __construct(
        private ClassReflection $classReflection,
        private string $methodName,
        private array $variants,
    ) {
    }

    /**
     * Get the class declaring the method.
     */
    public function getDeclaringClass(): ClassReflection
    {
        return $this->classReflection;
    }

    /**
     * Determine whether the method is static.
     */
    public function isStatic(): bool
    {
        return false;
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
     * Get the method's doc comment.
     */
    public function getDocComment(): ?string
    {
        return null;
    }

    /**
     * Get the method name.
     */
    public function getName(): string
    {
        return $this->methodName;
    }

    /**
     * Get the method prototype.
     */
    public function getPrototype(): ClassMemberReflection
    {
        return $this;
    }

    /**
     * Get the method's variants.
     *
     * @return list<ParametersAcceptor>
     */
    public function getVariants(): array
    {
        return $this->variants;
    }

    /**
     * Determine whether the method is deprecated.
     */
    public function isDeprecated(): TrinaryLogic
    {
        return TrinaryLogic::createNo();
    }

    /**
     * Get the method's deprecation description.
     */
    public function getDeprecatedDescription(): ?string
    {
        return null;
    }

    /**
     * Determine whether the method is final.
     */
    public function isFinal(): TrinaryLogic
    {
        return TrinaryLogic::createNo();
    }

    /**
     * Determine whether the method is internal.
     */
    public function isInternal(): TrinaryLogic
    {
        return TrinaryLogic::createNo();
    }

    /**
     * Get the type the method throws.
     */
    public function getThrowType(): ?Type
    {
        return null;
    }

    /**
     * Determine whether the method has side effects.
     */
    public function hasSideEffects(): TrinaryLogic
    {
        // Hypervel: upstream reports maybe, but each dynamic method stores its value on the receiver.
        return TrinaryLogic::createYes();
    }
}
