<?php

declare(strict_types=1);

namespace Hypervel\Data\Support\Factories;

use Hypervel\Contracts\Container\ContextualAttribute;
use Hypervel\Data\Support\DataParameter;
use Hypervel\Support\Reflector;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionParameter;

class DataParameterFactory
{
    /**
     * Create a new data parameter factory.
     */
    public function __construct(
        protected readonly DataTypeFactory $typeFactory,
    ) {
    }

    /**
     * Build an immutable parameter definition.
     *
     * @param ReflectionClass<object> $reflectionClass
     */
    public function build(
        ReflectionParameter $reflectionParameter,
        ReflectionClass $reflectionClass,
    ): DataParameter {
        // REMOVED: Data-specific From* aliases; every Hypervel contextual attribute works directly.
        return new DataParameter(
            name: $reflectionParameter->name,
            position: $reflectionParameter->getPosition(),
            isPromoted: $reflectionParameter->isPromoted(),
            isVariadic: $reflectionParameter->isVariadic(),
            hasDefaultValue: $reflectionParameter->isDefaultValueAvailable(),
            hasAttributes: $reflectionParameter->getAttributes() !== [],
            className: Reflector::getParameterClassName($reflectionParameter),
            type: $this->typeFactory->build(
                $reflectionParameter->getType(),
                $reflectionClass,
                $reflectionParameter,
            ),
            reflection: $reflectionParameter,
            contextualAttribute: $reflectionParameter->getAttributes(
                ContextualAttribute::class,
                ReflectionAttribute::IS_INSTANCEOF,
            )[0] ?? null,
        );
    }
}
