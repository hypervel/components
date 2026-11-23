<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Reflection;

use Hypervel\Database\Eloquent\Builder;
use PHPStan\Reflection\ClassMemberReflection;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\FunctionVariant;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\ParameterReflection;
use PHPStan\TrinaryLogic;
use PHPStan\Type\Generic\TemplateTypeMap;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;

/**
 * A builder method synthesized for a call that the builder's __call() handles.
 *
 * Unlike upstream, only dynamic where clauses, such as whereEmail(), are synthesized, so ModelPropertyColumnRule
 * recognizes them by this class.
 */
class EloquentBuilderMethodReflection implements MethodReflection
{
    private Type $returnType;

    /**
     * Create an Eloquent builder method reflection.
     *
     * @param list<ParameterReflection> $parameters
     */
    public function __construct(
        private string $methodName,
        private ClassReflection $classReflection,
        private array $parameters,
        ?Type $returnType = null,
        private bool $isVariadic = false,
    ) {
        $this->returnType = $returnType ?? new ObjectType(Builder::class);
    }

    /**
     * Return the declaring class.
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
     * Return the method variants.
     *
     * @return list<FunctionVariant>
     */
    public function getVariants(): array
    {
        return [
            new FunctionVariant(
                TemplateTypeMap::createEmpty(),
                null,
                $this->parameters,
                $this->isVariadic,
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
