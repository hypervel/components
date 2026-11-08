<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Reflection;

use PHPStan\Reflection\ParameterReflection;
use PHPStan\Reflection\PassedByReference;
use PHPStan\Type\Type;

use function array_map;

/**
 * Describe a target method's by-reference parameter as a proxy receives it. A proxy's __call()
 * gets the argument values, so the caller's variable is never passed by reference.
 */
class ProxiedParameterReflection implements ParameterReflection
{
    /**
     * Create a proxied parameter reflection.
     */
    public function __construct(
        private readonly ParameterReflection $parameter,
    ) {
    }

    /**
     * Describe a target's parameters as a proxy receives them.
     *
     * Other parameters stay as they are, keeping PHPStan's extended metadata such as closure-this types.
     *
     * @param list<ParameterReflection> $parameters
     * @return list<ParameterReflection>
     */
    public static function fromTargetParameters(array $parameters): array
    {
        return array_map(
            static fn (ParameterReflection $parameter): ParameterReflection => $parameter->passedByReference()->no()
                ? $parameter
                : new self($parameter),
            $parameters,
        );
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
        return $this->parameter->getType();
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
        return $this->parameter->isVariadic();
    }

    /**
     * Return the default value type.
     */
    public function getDefaultValue(): ?Type
    {
        return $this->parameter->getDefaultValue();
    }
}
