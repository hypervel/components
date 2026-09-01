<?php

declare(strict_types=1);

namespace Hypervel\Data\Casts;

use Hypervel\Data\Support\Creation\ConstructionState;
use Hypervel\Data\Support\Creation\CreationContext;
use Hypervel\Data\Support\DataProperty;

class BuiltinTypeCast implements Cast, IterableItemCast
{
    /**
     * Create a built-in type cast.
     *
     * @param 'bool'|'int'|'float'|'array'|'string' $type
     */
    public function __construct(
        protected string $type,
    ) {
    }

    /**
     * Cast a property value to the configured built-in type.
     */
    public function cast(
        DataProperty $property,
        mixed $value,
        ConstructionState $state,
        CreationContext $context,
    ): mixed {
        return $this->runCast($value);
    }

    /**
     * Cast an iterable item to the configured built-in type.
     */
    public function castIterableItem(
        DataProperty $property,
        mixed $value,
        ConstructionState $state,
        CreationContext $context,
    ): mixed {
        return $this->runCast($value);
    }

    /**
     * Cast one value to the configured type.
     */
    protected function runCast(mixed $value): mixed
    {
        return match ($this->type) {
            'bool' => $this->castToBool($value),
            'int' => (int) $value,
            'float' => (float) $value,
            'array' => (array) $value,
            'string' => (string) $value,
        };
    }

    /**
     * Cast one value to a boolean.
     */
    protected function castToBool(mixed $value): bool
    {
        if (! is_string($value)) {
            return (bool) $value;
        }

        return match (strtolower($value)) {
            'true' => true,
            'false' => false,
            default => (bool) $value,
        };
    }
}
