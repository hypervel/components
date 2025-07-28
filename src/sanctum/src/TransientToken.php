<?php

declare(strict_types=1);

namespace Hypervel\Sanctum;

use BackedEnum;
use Hypervel\Sanctum\Contracts\HasAbilities;

class TransientToken implements HasAbilities
{
    /**
     * Determine if the token has a given ability.
     */
    public function can(BackedEnum|string $ability): bool
    {
        return true;
    }

    /**
     * Determine if the token is missing a given ability.
     */
    public function cant(BackedEnum|string $ability): bool
    {
        return false;
    }
}
