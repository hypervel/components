<?php

declare(strict_types=1);

namespace Hypervel\Container;

use ReflectionAttribute;

/**
 * Cached reflection analysis used to resolve an unbound concrete class.
 *
 * @internal
 */
readonly class BuildRecipe
{
    /**
     * @param ReflectionAttribute[] $classAttributes
     * @param ParameterRecipe[] $parameters
     */
    public function __construct(
        public bool $classExists,
        public bool $isInstantiable,
        public bool $hasConstructor,
        public array $classAttributes,
        public array $parameters,
        public bool $executionScoped,
    ) {
    }
}
