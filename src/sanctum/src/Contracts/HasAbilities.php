<?php

declare(strict_types=1);

namespace Hypervel\Sanctum\Contracts;

use UnitEnum;

interface HasAbilities
{
    /**
     * Determine if the token has a given ability.
     */
    public function can(UnitEnum|string $ability): bool;

    /**
     * Determine if the token is missing a given ability.
     */
    public function cant(UnitEnum|string $ability): bool;
}
