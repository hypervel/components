<?php

declare(strict_types=1);

namespace Hypervel\Database\PHPStan;

use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ParameterReflection;
use PHPStan\Reflection\PassedByReference;
use PHPStan\Type\Type;

/**
 * Preserve model-relative parameter types on an exposed scope method.
 */
class ModelScopeParameterReflection implements ParameterReflection
{
    /**
     * Create a model scope parameter reflection.
     */
    public function __construct(
        private readonly ParameterReflection $parameter,
        private readonly ClassReflection $modelClass,
    ) {
    }

    /**
     * Return the parameter name.
     */
    public function getName(): string
    {
        return $this->parameter->getName();
    }

    /**
     * Determine whether the parameter is optional.
     */
    public function isOptional(): bool
    {
        return $this->parameter->isOptional();
    }

    /**
     * Return the parameter type.
     */
    public function getType(): Type
    {
        return ModelScopeTypeResolver::bindToModel($this->parameter->getType(), $this->modelClass);
    }

    /**
     * Return the parameter reference mode.
     */
    public function passedByReference(): PassedByReference
    {
        return $this->parameter->passedByReference();
    }

    /**
     * Determine whether the parameter is variadic.
     */
    public function isVariadic(): bool
    {
        return $this->parameter->isVariadic();
    }

    /**
     * Return the default value type.
     */
    public function getDefaultValue(): ?Type
    {
        $defaultValue = $this->parameter->getDefaultValue();

        return $defaultValue === null
            ? null
            : ModelScopeTypeResolver::bindToModel($defaultValue, $this->modelClass);
    }
}
