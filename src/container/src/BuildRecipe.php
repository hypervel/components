<?php

declare(strict_types=1);

namespace Hypervel\Container;

use ReflectionAttribute;

/**
 * Cached build instructions for a concrete class.
 *
 * Stores the result of analyzing a class via reflection: whether it exists,
 * is instantiable, has a constructor, its class-level attributes, and its
 * constructor parameters (as ParameterRecipe objects). Created once per class
 * per worker lifetime by Container::computeBuildRecipe().
 */
readonly class BuildRecipe
{
    /**
     * @param ReflectionAttribute[] $classAttributes
     * @param ParameterRecipe[] $parameters
     */
    public function __construct(
        public string $className,
        public bool $classExists,
        public bool $isInstantiable,
        public bool $hasConstructor,
        public array $classAttributes,
        public array $parameters,
    ) {
    }
}
