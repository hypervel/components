<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Reflection;

use PHPStan\Reflection\ClassMemberReflection;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\FunctionVariant;
use PHPStan\Reflection\MethodReflection;
use PHPStan\TrinaryLogic;
use PHPStan\Type\Generic\TemplateTypeMap;
use PHPStan\Type\Type;

/**
 * Describe a relation method that a __call() handles without arguments, such as a relation that
 * resolveRelationUsing() registers or a model's through{Relation}().
 */
class DynamicRelationMethodReflection implements MethodReflection
{
    /**
     * Create a dynamic relation method reflection.
     */
    public function __construct(
        private readonly ClassReflection $declaringClass,
        private readonly string $methodName,
        private readonly Type $returnType,
        private readonly bool $isStatic,
    ) {
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
     * Return the method name.
     */
    public function getName(): string
    {
        return $this->methodName;
    }

    /**
     * Return the method prototype.
     */
    public function getPrototype(): ClassMemberReflection
    {
        return $this;
    }

    /**
     * Return the method's single variant, which takes no arguments.
     *
     * @return list<FunctionVariant>
     */
    public function getVariants(): array
    {
        return [
            new FunctionVariant(
                TemplateTypeMap::createEmpty(),
                null,
                [],
                false,
                $this->returnType,
            ),
        ];
    }

    /**
     * Return no docblock for the synthesized signature.
     */
    public function getDocComment(): ?string
    {
        return null;
    }

    /**
     * Determine whether the method is deprecated.
     */
    public function isDeprecated(): TrinaryLogic
    {
        return TrinaryLogic::createNo();
    }

    /**
     * Return the deprecation description.
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
     * Return the declared throw type.
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
        return TrinaryLogic::createMaybe();
    }
}
