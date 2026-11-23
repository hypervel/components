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
     * Return the parameter name.
     */
    public function getName(): string
    {
        return 'value';
    }

    /**
     * Determine whether the parameter is optional.
     */
    public function isOptional(): bool
    {
        return true;
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
