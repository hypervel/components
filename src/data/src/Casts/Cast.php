<?php

declare(strict_types=1);

namespace Hypervel\Data\Casts;

use Hypervel\Data\Support\Creation\CreationContext;
use Hypervel\Data\Support\DataProperty;

// REMOVED: UnserializeCast accepted serialized request input; use a custom Cast for trusted formats.
interface Cast
{
    /**
     * Cast a property value.
     *
     * @param array<string, mixed> $properties the object's declared property values, keyed by property name
     */
    public function cast(
        DataProperty $property,
        mixed $value,
        array $properties,
        CreationContext $context,
    ): mixed;
}
