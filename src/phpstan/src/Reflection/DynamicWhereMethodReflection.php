<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Reflection;

use PHPStan\Reflection\ClassMemberReflection;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\FunctionVariant;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\ParameterReflection;
use PHPStan\Reflection\PassedByReference;
use PHPStan\TrinaryLogic;
use PHPStan\Type\Generic\TemplateTypeMap;
use PHPStan\Type\MixedType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;

class DynamicWhereMethodReflection implements MethodReflection
{
    /**
     * Create a new method reflection instance.
     */
    public function __construct(private ClassReflection $classReflection, private string $methodName)
    {
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
     * Get the method's single variant, which accepts one value and returns the object.
     *
     * @return FunctionVariant[]
     */
    public function getVariants(): array
    {
        return [
            new FunctionVariant(
                TemplateTypeMap::createEmpty(),
                TemplateTypeMap::createEmpty(),
                [
                    new class implements ParameterReflection {
                        /**
                         * Get the parameter name.
                         */
                        public function getName(): string
                        {
                            return 'dynamic-with';
                        }

                        /**
                         * Determine whether the parameter is optional.
                         */
                        public function isOptional(): bool
                        {
                            return false;
                        }

                        /**
                         * Get the parameter type.
                         */
                        public function getType(): Type
                        {
                            return new MixedType;
                        }

                        /**
                         * Determine how the parameter is passed.
                         */
                        public function passedByReference(): PassedByReference
                        {
                            return PassedByReference::createNo();
                        }

                        /**
                         * Determine whether the parameter is variadic.
                         */
                        public function isVariadic(): bool
                        {
                            return false;
                        }

                        /**
                         * Get the parameter's default value.
                         */
                        public function getDefaultValue(): ?Type
                        {
                            return null;
                        }
                    },
                ],
                false,
                new ObjectType($this->classReflection->getName()),
            ),
        ];
    }

    /**
     * Determine whether the method is deprecated.
     */
    public function isDeprecated(): TrinaryLogic
    {
        return TrinaryLogic::createNo();
    }

    /**
     * Get the deprecation description.
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
        // Upstream reports none, which makes PHPStan call an unused $response->withFoo()
        // statement useless, but the call stores the value on the response or view.
        return TrinaryLogic::createYes();
    }
}
