<?php

declare(strict_types=1);

namespace Hypervel\Database\PHPStan;

use PHPStan\Reflection\ClassMemberReflection;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ExtendedParametersAcceptor;
use PHPStan\Reflection\FunctionVariant;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\ParameterReflection;
use PHPStan\Reflection\ParametersAcceptor;
use PHPStan\TrinaryLogic;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;

/**
 * Describe a model scope as invoked through an Eloquent receiver.
 *
 * Relation scopes run on the relation's query but return the relation when the
 * result is that query, matching ForwardsCalls::forwardDecoratedCallTo().
 */
class NamedScopeMethodReflection implements MethodReflection
{
    /**
     * Create a named scope method reflection.
     */
    public function __construct(
        private readonly ClassReflection $declaringClass,
        private readonly ClassReflection $modelClass,
        private readonly MethodReflection $scopeMethod,
        private readonly string $methodName,
        private readonly Type $receiverType,
        private readonly Type $queryType,
        private readonly bool $static,
    ) {
    }

    /**
     * Return the exposed scope name.
     */
    public function getName(): string
    {
        return $this->methodName;
    }

    /**
     * Return the declaring class.
     */
    public function getDeclaringClass(): ClassReflection
    {
        return $this->declaringClass;
    }

    /**
     * Determine whether the exposed scope is static.
     */
    public function isStatic(): bool
    {
        return $this->static;
    }

    /**
     * Determine whether the exposed scope is private.
     */
    public function isPrivate(): bool
    {
        return false;
    }

    /**
     * Determine whether the exposed scope is public.
     */
    public function isPublic(): bool
    {
        return true;
    }

    /**
     * Return no docblock for the synthesized signature.
     */
    public function getDocComment(): ?string
    {
        return null;
    }

    /**
     * Return the method prototype.
     */
    public function getPrototype(): ClassMemberReflection
    {
        return $this;
    }

    /**
     * Return scope variants without the engine-supplied builder parameter.
     *
     * @return list<ParametersAcceptor>
     */
    public function getVariants(): array
    {
        return array_map(
            fn (ParametersAcceptor $variant): FunctionVariant => new FunctionVariant(
                $variant->getTemplateTypeMap(),
                $variant->getResolvedTemplateTypeMap(),
                array_map(
                    fn (ParameterReflection $parameter): ModelScopeParameterReflection => new ModelScopeParameterReflection(
                        $parameter,
                        $this->modelClass,
                    ),
                    array_slice($variant->getParameters(), 1),
                ),
                $variant->isVariadic(),
                $this->returnType($variant->getReturnType()),
                $variant instanceof ExtendedParametersAcceptor ? $variant->getCallSiteVarianceMap() : null,
            ),
            $this->scopeMethod->getVariants(),
        );
    }

    /**
     * Determine whether the method is deprecated.
     */
    public function isDeprecated(): TrinaryLogic
    {
        return $this->scopeMethod->isDeprecated();
    }

    /**
     * Return the deprecation description.
     */
    public function getDeprecatedDescription(): ?string
    {
        return $this->scopeMethod->getDeprecatedDescription();
    }

    /**
     * Determine whether the method is final.
     */
    public function isFinal(): TrinaryLogic
    {
        return $this->scopeMethod->isFinal();
    }

    /**
     * Determine whether the method is internal.
     */
    public function isInternal(): TrinaryLogic
    {
        return $this->scopeMethod->isInternal();
    }

    /**
     * Return the declared throw type.
     */
    public function getThrowType(): ?Type
    {
        return $this->scopeMethod->getThrowType();
    }

    /**
     * Determine whether the method has side effects.
     */
    public function hasSideEffects(): TrinaryLogic
    {
        return $this->scopeMethod->hasSideEffects();
    }

    /**
     * Resolve the result produced by Builder::callScope().
     */
    private function returnType(Type $returnType): Type
    {
        $returnType = ModelScopeTypeResolver::bindToModel($returnType, $this->modelClass);
        $nonNullReturnType = TypeCombinator::removeNull($returnType);

        if ($nonNullReturnType->isVoid()->yes()
            || $returnType->isNull()->yes()
            || $nonNullReturnType->isSuperTypeOf($this->queryType)->yes()) {
            return $this->receiverType;
        }

        if (TypeCombinator::containsNull($returnType)) {
            return TypeCombinator::union(
                $nonNullReturnType,
                $this->receiverType,
            );
        }

        return $returnType;
    }
}
