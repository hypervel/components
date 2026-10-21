<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Auth\Access;

use Hypervel\Auth\Access\AuthorizationException;
use Hypervel\Auth\Access\Response;
use Hypervel\Container\Container;
use Hypervel\Contracts\Auth\Access\Gate;
use UnitEnum;

trait Authorizable
{
    /**
     * Determine if the given ability should be granted for the entity.
     *
     * @throws AuthorizationException
     */
    public function authorize(UnitEnum|string $ability, mixed $arguments = []): Response
    {
        return Container::getInstance()->make(Gate::class)->forUser($this)->authorize($ability, $arguments);
    }

    /**
     * Determine if the entity has the given abilities.
     */
    public function can(iterable|UnitEnum|string $abilities, mixed $arguments = []): bool
    {
        return Container::getInstance()->make(Gate::class)->forUser($this)->check($abilities, $arguments);
    }

    /**
     * Determine if the entity has any of the given abilities.
     */
    public function canAny(iterable|UnitEnum|string $abilities, mixed $arguments = []): bool
    {
        return Container::getInstance()->make(Gate::class)->forUser($this)->any($abilities, $arguments);
    }

    /**
     * Determine if the entity does not have the given abilities.
     */
    public function cant(iterable|UnitEnum|string $abilities, mixed $arguments = []): bool
    {
        return ! $this->can($abilities, $arguments);
    }

    /**
     * Determine if the entity does not have the given abilities.
     */
    public function cannot(iterable|UnitEnum|string $abilities, mixed $arguments = []): bool
    {
        return $this->cant($abilities, $arguments);
    }
}
