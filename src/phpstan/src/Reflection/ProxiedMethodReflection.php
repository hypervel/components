<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Reflection;

use Closure;
use PHPStan\Reflection\ClassMemberReflection;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ExtendedParametersAcceptor;
use PHPStan\Reflection\FunctionVariant;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\ParameterReflection;
use PHPStan\Reflection\ParametersAcceptor;
use PHPStan\TrinaryLogic;
use PHPStan\Type\StaticType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeTraverser;

use function array_map;

/**
 * Describe a method that a proxy passes to its target, returning what the proxy returns.
 */
final class ProxiedMethodReflection implements MethodReflection
{
    /**
     * Create a proxied method reflection.
     *
     * @param Closure(Type): Type $returnType gets what the proxy returns for the target method's return type
     */
    public function __construct(
        private readonly ClassReflection $declaringClass,
        private readonly MethodReflection $targetMethod,
        private readonly Closure $returnType,
    ) {
    }

    /**
     * Return the method name.
     */
    public function getName(): string
    {
        return $this->targetMethod->getName();
    }

    /**
     * Return the proxy class.
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
     * Return the method docblock.
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
     * Return the target method's variants with the proxy's parameters and return types.
     *
     * @return list<ParametersAcceptor>
     */
    public function getVariants(): array
    {
        return array_map(
            fn (ParametersAcceptor $variant): FunctionVariant => new FunctionVariant(
                $variant->getTemplateTypeMap(),
                $variant->getResolvedTemplateTypeMap(),
                // Other parameters stay as they are, keeping PHPStan's extended metadata such as closure-this types.
                array_map(
                    static fn (ParameterReflection $parameter): ParameterReflection => $parameter->passedByReference()->no()
                        ? $parameter
                        : new ProxiedParameterReflection($parameter),
                    $variant->getParameters(),
                ),
                $variant->isVariadic(),
                self::resolveStaticTypes(($this->returnType)($variant->getReturnType())),
                $variant instanceof ExtendedParametersAcceptor ? $variant->getCallSiteVarianceMap() : null,
            ),
            $this->targetMethod->getVariants(),
        );
    }

    /**
     * Resolve the static types in a type the proxy returns to their classes. PHPStan binds the
     * static types of a proxy's members to the proxy, while they describe the proxy's target.
     */
    public static function resolveStaticTypes(Type $type): Type
    {
        return TypeTraverser::map(
            $type,
            static fn (Type $type, callable $traverse): Type => $type instanceof StaticType ? $type->getStaticObjectType() : $traverse($type),
        );
    }

    /**
     * Determine whether the target method is deprecated.
     */
    public function isDeprecated(): TrinaryLogic
    {
        return $this->targetMethod->isDeprecated();
    }

    /**
     * Return the target method's deprecation description.
     */
    public function getDeprecatedDescription(): ?string
    {
        return $this->targetMethod->getDeprecatedDescription();
    }

    /**
     * Determine whether the method is final.
     */
    public function isFinal(): TrinaryLogic
    {
        return TrinaryLogic::createNo();
    }

    /**
     * Determine whether the target method is internal.
     */
    public function isInternal(): TrinaryLogic
    {
        return $this->targetMethod->isInternal();
    }

    /**
     * Return the target method's throw type.
     */
    public function getThrowType(): ?Type
    {
        return $this->targetMethod->getThrowType();
    }

    /**
     * Determine whether the method has side effects.
     */
    public function hasSideEffects(): TrinaryLogic
    {
        return TrinaryLogic::createMaybe();
    }
}
