<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent\Concerns;

use Hypervel\Database\Eloquent\Attributes\Scope;
use ReflectionMethod;

/**
 * Adds support for the #[Scope] attribute on model methods.
 *
 * This trait allows methods to be marked as local scopes without
 * requiring the traditional 'scope' prefix naming convention.
 */
trait HasLocalScopes
{
    /**
     * Determine if the model has a named scope.
     *
     * Checks for both traditional scope prefix (scopeActive) and
     * methods marked with the #[Scope] attribute.
     */
    public function hasNamedScope(string $scope): bool
    {
        return method_exists($this, 'scope' . ucfirst($scope))
            || static::isScopeMethodWithAttribute($scope);
    }

    /**
     * Apply the given named scope if possible.
     *
     * @param array<int, mixed> $parameters
     */
    public function callNamedScope(string $scope, array $parameters = []): mixed
    {
        if (static::isScopeMethodWithAttribute($scope)) {
            return $this->{$scope}(...$parameters);
        }

        return $this->{'scope' . ucfirst($scope)}(...$parameters);
    }

    /**
     * Determine if the given method has a #[Scope] attribute.
     */
    protected static function isScopeMethodWithAttribute(string $method): bool
    {
        if (! method_exists(static::class, $method)) {
            return false;
        }

        return (new ReflectionMethod(static::class, $method))
            ->getAttributes(Scope::class) !== [];
    }
}
