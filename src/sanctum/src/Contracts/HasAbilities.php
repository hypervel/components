<?php

declare(strict_types=1);

namespace Hypervel\Sanctum\Contracts;

interface HasAbilities
{
    /**
     * Determine if the token has a given ability.
     */
    public function can(string $ability): bool;

    /**
     * Determine if the token is missing a given ability.
     */
    public function cant(string $ability): bool;
}
