<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Reflection;

use PHPStan\Reflection\ClassMemberReflection;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\FunctionVariant;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\ParametersAcceptor;
use PHPStan\TrinaryLogic;
use PHPStan\Type\Type;

use function array_map;

class StaticMethodReflection implements MethodReflection
{
    /**
     * Create a static method reflection.
     */
    public function __construct(private MethodReflection $methodReflection)
    {
    }

    /**
     * Return the method's declaring class.
     */
    public function getDeclaringClass(): ClassReflection
    {
        return $this->methodReflection->getDeclaringClass();
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
        return $this->methodReflection->isPrivate();
    }

    /**
     * Determine whether the method is public.
     */
    public function isPublic(): bool
    {
        return $this->methodReflection->isPublic();
    }

    /**
     * Return the method docblock.
     */
    public function getDocComment(): ?string
    {
        return $this->methodReflection->getDocComment();
    }

    /**
     * Return the method name.
     */
    public function getName(): string
    {
        return $this->methodReflection->getName();
    }

    /**
     * Return the method prototype.
     */
    public function getPrototype(): ClassMemberReflection
    {
        return $this->methodReflection->getPrototype();
    }

    /**
     * Return the method variants as the forwarding call receives them.
     *
     * @return list<ParametersAcceptor>
     */
    public function getVariants(): array
    {
        // Hypervel: facades and macros pass the target its argument values and return the target's own result,
        // so by-reference parameters never receive the caller's variable and static returns describe the target.
        return array_map(
            static fn (ParametersAcceptor $variant): FunctionVariant => ProxiedMethodReflection::proxyVariant(
                $variant,
                $variant->getReturnType(),
            ),
            $this->methodReflection->getVariants(),
        );
    }

    /**
     * Determine whether the method is deprecated.
     */
    public function isDeprecated(): TrinaryLogic
    {
        return $this->methodReflection->isDeprecated();
    }

    /**
     * Return the method's deprecation description.
     */
    public function getDeprecatedDescription(): ?string
    {
        return $this->methodReflection->getDeprecatedDescription();
    }

    /**
     * Determine whether the method is final.
     */
    public function isFinal(): TrinaryLogic
    {
        return $this->methodReflection->isFinal();
    }

    /**
     * Determine whether the method is internal.
     */
    public function isInternal(): TrinaryLogic
    {
        return $this->methodReflection->isInternal();
    }

    /**
     * Return the method's throw type.
     */
    public function getThrowType(): ?Type
    {
        return $this->methodReflection->getThrowType();
    }

    /**
     * Determine whether the method has side effects.
     */
    public function hasSideEffects(): TrinaryLogic
    {
        return $this->methodReflection->hasSideEffects();
    }
}
