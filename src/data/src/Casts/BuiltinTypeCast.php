<?php

declare(strict_types=1);

namespace Hypervel\Data\Casts;

use Hypervel\Data\Support\Creation\ConstructionState;
use Hypervel\Data\Support\Creation\CreationContext;
use Hypervel\Data\Support\Creation\ValueCaster;
use Hypervel\Data\Support\DataProperty;

class BuiltinTypeCast implements Cast, IterableItemCast
{
    /**
     * Create a built-in type cast.
     *
     * @param 'array'|'bool'|'float'|'int'|'string' $type
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
        return ValueCaster::castBuiltin($this->type, $value);
    }
}
