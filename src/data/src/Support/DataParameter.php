<?php

declare(strict_types=1);

namespace Hypervel\Data\Support;

use Hypervel\Contracts\Container\ContextualAttribute;
use ReflectionAttribute;
use ReflectionParameter;

class DataParameter
{
    /**
     * Create a new data parameter definition.
     *
     * @param null|class-string $className
     * @param null|ReflectionAttribute<ContextualAttribute> $contextualAttribute
     */
    public function __construct(
        public readonly string $name,
        public readonly int $position,
        public readonly bool $isPromoted,
        public readonly bool $isVariadic,
        public readonly bool $hasDefaultValue,
        public readonly bool $hasAttributes,
        public readonly ?string $className,
        public readonly DataType $type,
        public readonly ReflectionParameter $reflection,
        public readonly ?ReflectionAttribute $contextualAttribute,
    ) {
    }
}
