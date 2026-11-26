<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Reflection;

use PHPStan\Reflection\ParameterReflection;
use PHPStan\Reflection\PassedByReference;
use PHPStan\Type\MixedType;
use PHPStan\Type\Type;

class DynamicWhereParameterReflection implements ParameterReflection
{
    /**
     * Create a new dynamic method parameter reflection.
     *
     * Hypervel: upstream's value is always named and optional, but a dynamic where or with*() reads each value
     * by its position, so its parameters are required and unnamed, as PHPStan names a callable type's parameters.
     */
    public function __construct(private string $name = '', private bool $optional = false)
    {
    }

    /**
     * Return the parameter name.
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Determine whether the parameter is optional.
     */
    public function isOptional(): bool
    {
        return $this->optional;
    }

    /**
     * Return the parameter type.
     */
    public function getType(): Type
    {
        return new MixedType;
    }

    /**
     * Return the parameter reference mode.
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
     * Return the default value type.
     */
    public function getDefaultValue(): ?Type
    {
        return null;
    }
}
