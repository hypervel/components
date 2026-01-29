<?php

declare(strict_types=1);

namespace Hypervel\Sanctum;

use Hypervel\Sanctum\Contracts\HasAbilities;
use UnitEnum;

class TransientToken implements HasAbilities
{
    /**
     * Determine if the token has a given ability.
     */
    public function can(UnitEnum|string $ability): bool
    {
        return true;
    }

    /**
     * Determine if the token is missing a given ability.
     */
    public function cant(UnitEnum|string $ability): bool
    {
        return false;
    }
}
