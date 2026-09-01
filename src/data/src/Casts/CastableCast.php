<?php

declare(strict_types=1);

namespace Hypervel\Data\Casts;

use Hypervel\Data\Support\Creation\ConstructionState;
use Hypervel\Data\Support\Creation\CreationContext;
use Hypervel\Data\Support\DataProperty;

class CastableCast implements Cast
{
    protected ?Cast $cast = null;

    /**
     * Create a cast backed by a Castable type.
     *
     * @param class-string<Castable> $castableClass
     * @param list<mixed> $arguments
     */
    public function __construct(
        public readonly string $castableClass,
        public readonly array $arguments = [],
    ) {
    }

    /**
     * Cast a value through the declared Castable type.
     */
    public function cast(
        DataProperty $property,
        mixed $value,
        ConstructionState $state,
        CreationContext $context,
    ): mixed {
        $this->cast ??= $this->castableClass::dataCastUsing($this->arguments);

        return $this->cast->cast($property, $value, $state, $context);
    }
}
