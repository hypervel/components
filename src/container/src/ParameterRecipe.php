<?php

declare(strict_types=1);

namespace Hypervel\Container;

use ReflectionAttribute;

/**
 * Cached constructor parameter metadata.
 *
 * Stores the result of analyzing a single constructor parameter via
 * reflection. Created once per parameter per class per worker lifetime
 * by Container::computeBuildRecipe(), avoiding repeated reflection on
 * every build() call.
 */
readonly class ParameterRecipe
{
    /**
     * @param ReflectionAttribute[] $attributes
     */
    public function __construct(
        public string $name,
        public int $position,
        public string $declaringClassName,
        public ?string $className,
        public bool $hasType,
        public bool $hasDefault,
        public mixed $default,
        public bool $isVariadic,
        public bool $isOptional,
        public bool $allowsNull,
        public array $attributes,
        public ?ReflectionAttribute $contextualAttribute,
    ) {
    }
}
