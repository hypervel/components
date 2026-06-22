<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Auth\Access;

use UnitEnum;

interface Authorizable
{
    /**
     * Determine if the entity has a given ability.
     */
    public function can(iterable|UnitEnum|string $abilities, mixed $arguments = []): bool;
}
