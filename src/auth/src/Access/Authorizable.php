<?php

declare(strict_types=1);

namespace Hypervel\Auth\Access;

use Hyperf\Context\ApplicationContext;
use Hypervel\Auth\Contracts\Gate;

trait Authorizable
{
    /**
     * Determine if the entity has the given abilities.
     */
    public function can(iterable|string $abilities, mixed $arguments = []): bool
    {
        return ApplicationContext::getContainer()->get(Gate::class)->forUser($this)->check($abilities, $arguments);
    }

    /**
     * Determine if the entity has any of the given abilities.
     */
    public function canAny(iterable|string $abilities, mixed $arguments = []): bool
    {
        return ApplicationContext::getContainer()->get(Gate::class)->forUser($this)->any($abilities, $arguments);
    }

    /**
     * Determine if the entity does not have the given abilities.
     */
    public function cant(iterable|string $abilities, mixed $arguments = []): bool
    {
        return ! $this->can($abilities, $arguments);
    }

    /**
     * Determine if the entity does not have the given abilities.
     */
    public function cannot(iterable|string $abilities, mixed $arguments = []): bool
    {
        return $this->cant($abilities, $arguments);
    }
}
