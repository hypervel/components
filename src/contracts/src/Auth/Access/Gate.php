<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Auth\Access;

use Hypervel\Auth\Access\AuthorizationException;
use Hypervel\Auth\Access\Response;
use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Query\Expression;
use InvalidArgumentException;
use RuntimeException;
use UnitEnum;

interface Gate
{
    /**
     * Determine if a given ability has been defined.
     */
    public function has(array|UnitEnum|string $ability): bool;

    /**
     * Define a new ability.
     *
     * @throws InvalidArgumentException
     */
    public function define(UnitEnum|string $ability, array|callable|string $callback): static;

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
     * Determine if all of the given abilities should be granted for the current user.
     */
    public function allows(iterable|UnitEnum|string $ability, mixed $arguments = []): bool;

    /**
     * Determine if any of the given abilities should be denied for the current user.
     */
    public function denies(iterable|UnitEnum|string $ability, mixed $arguments = []): bool;

    /**
     * Determine if all of the given abilities should be granted for the current user.
     */
    public function check(iterable|UnitEnum|string $abilities, mixed $arguments = []): bool;

    /**
     * Determine if any one of the given abilities should be granted for the current user.
     */
    public function any(iterable|UnitEnum|string $abilities, mixed $arguments = []): bool;

    /**
     * Determine if the given ability should be granted for the current user.
     *
     * @throws AuthorizationException
     */
    public function authorize(UnitEnum|string $ability, mixed $arguments = []): Response;

    /**
     * Inspect the user for the given ability.
     */
    public function inspect(UnitEnum|string $ability, mixed $arguments = []): Response;

    /**
     * Get the raw result from the authorization callback.
     *
     * @throws AuthorizationException
     */
    public function raw(string $ability, mixed $arguments = []): mixed;

    /**
     * Apply the policy's scope method to filter a query to authorized rows.
     *
     * @throws RuntimeException
     */
    public function scope(string $ability, Builder $query): Builder;

    /**
     * Get a SQL expression from the policy for per-row authorization.
     *
     * @param Builder|class-string<Model>|Model $query
     *
     * @throws RuntimeException
     */
    public function select(string $ability, Builder|Model|string $query): Expression;

    /**
     * Get a policy instance for a given class.
     */
    public function getPolicyFor(object|string $class): mixed;

    /**
     * Get a gate instance for the given user.
     */
    public function forUser(mixed $user): static;

    /**
     * Get all of the defined abilities.
     */
    public function abilities(): array;
}
