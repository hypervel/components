<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Reflection;

use PHPStan\Reflection\ParameterReflection;
use PHPStan\Reflection\PassedByReference;
use PHPStan\Type\Type;

/**
 * A required parameter of a callable type the package builds, such as an expected eager-loading callback.
 *
 * PHPStan's own parameter reflections are not covered by its backward compatibility promise.
 */
class CallableParameterReflection implements ParameterReflection
{
    /**
     * Create a callable parameter reflection.
     */
    public function __construct(
        private readonly string $name,
        private readonly Type $type,
    ) {
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
        return false;
    }

    /**
     * Return the parameter type.
     */
    public function getType(): Type
    {
        return $this->type;
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
