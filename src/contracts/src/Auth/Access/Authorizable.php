<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Auth\Access;

use Hypervel\Auth\Access\AuthorizationException;
use Hypervel\Auth\Access\Response;
use UnitEnum;

interface Authorizable
{
    /**
     * Determine if the given ability should be granted for the entity.
     *
     * @throws AuthorizationException
     */
    public function authorize(UnitEnum|string $ability, mixed $arguments = []): Response;

    /**
     * Determine if the entity has a given ability.
     */
    public function can(iterable|UnitEnum|string $abilities, mixed $arguments = []): bool;
}
