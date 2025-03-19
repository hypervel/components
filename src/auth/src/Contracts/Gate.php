<?php

declare(strict_types=1);

namespace Hypervel\Auth\Contracts;

use Hypervel\Auth\Access\AuthorizationException;
use Hypervel\Auth\Access\Response;
use InvalidArgumentException;

interface Gate
{
    /**
     * Determine if a given ability has been defined.
     */
    public function has(string $ability): bool;

    /**
     * Define a new ability.
     */
    public function define(string $ability, callable|string $callback): static;

    /**
     * Define abilities for a resource.
     */
    public function resource(string $name, string $class, ?array $abilities = null): static;

    /**
     * Define a policy class for a given class type.
     */
    public function policy(string $class, string $policy): static;

    /**
     * Register a callback to run before all Gate checks.
     */
    public function before(callable $callback): static;

    /**
     * Register a callback to run after all Gate checks.
     */
    public function after(callable $callback): static;

    /**
     * Determine if the given ability should be granted for the current user.
     */
    public function allows(string $ability, mixed $arguments = []): bool;

    /**
     * Determine if the given ability should be denied for the current user.
     */
    public function denies(string $ability, mixed $arguments = []): bool;

    /**
     * Determine if all of the given abilities should be granted for the current user.
     */
    public function check(iterable|string $abilities, mixed $arguments = []): bool;

    /**
     * Determine if any one of the given abilities should be granted for the current user.
     */
    public function any(iterable|string $abilities, mixed $arguments = []): bool;

    /**
     * Determine if the given ability should be granted for the current user.
     *
     * @throws AuthorizationException
     */
    public function authorize(string $ability, mixed $arguments = []): Response;

    /**
     * Inspect the user for the given ability.
     */
    public function inspect(string $ability, mixed $arguments = []): Response;

    /**
     * Get the raw result from the authorization callback.
     *
     * @throws AuthorizationException
     */
    public function raw(string $ability, mixed $arguments = []): mixed;

    /**
     * Get a policy instance for a given class.
     *
     * @return mixed|void
     * @throws InvalidArgumentException
     */
    public function getPolicyFor(object|string $class);

    /**
     * Get a guard instance for the given user.
     */
    public function forUser(?Authenticatable $user): static;

    /**
     * Get all of the defined abilities.
     */
    public function abilities(): array;
}
