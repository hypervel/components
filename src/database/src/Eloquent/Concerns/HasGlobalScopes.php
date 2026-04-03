<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent\Concerns;

use Closure;
use Hypervel\Database\Eloquent\Attributes\ScopedBy;
use Hypervel\Database\Eloquent\Scope;
use Hypervel\Support\Arr;
use Hypervel\Support\Collection;
use InvalidArgumentException;
use ReflectionAttribute;
use ReflectionClass;

trait HasGlobalScopes
{
    /**
     * Boot the has global scopes trait for a model.
     */
    public static function bootHasGlobalScopes(): void
    {
        static::addGlobalScopes(static::resolveGlobalScopeAttributes());
    }

    /**
     * Resolve the global scope class names from the attributes.
     */
    public static function resolveGlobalScopeAttributes(): array
    {
        $reflectionClass = new ReflectionClass(static::class);

        $attributes = (new Collection($reflectionClass->getAttributes(ScopedBy::class, ReflectionAttribute::IS_INSTANCEOF)));

        foreach ($reflectionClass->getTraits() as $trait) {
            $attributes->push(...$trait->getAttributes(ScopedBy::class, ReflectionAttribute::IS_INSTANCEOF));
        }

        return $attributes->map(fn ($attribute) => $attribute->getArguments())
            ->flatten()
            ->all();
    }

    /**
     * Register a new global scope on the model.
     *
     * @param (Closure(\Hypervel\Database\Eloquent\Builder<static>): mixed)|\Hypervel\Database\Eloquent\Scope|string $scope
     * @param null|(Closure(\Hypervel\Database\Eloquent\Builder<static>): mixed)|\Hypervel\Database\Eloquent\Scope $implementation
     *
     * @throws InvalidArgumentException
     */
    public static function addGlobalScope(Scope|Closure|string $scope, Scope|Closure|null $implementation = null): mixed
    {
        if (is_string($scope) && ($implementation instanceof Closure || $implementation instanceof Scope)) {
            return static::$globalScopes[static::class][$scope] = $implementation;
        }
        if ($scope instanceof Closure) {
            return static::$globalScopes[static::class][spl_object_hash($scope)] = $scope;
        }
        if ($scope instanceof Scope) {
            return static::$globalScopes[static::class][get_class($scope)] = $scope;
        }
        if (class_exists($scope) && is_subclass_of($scope, Scope::class)) {
            return static::$globalScopes[static::class][$scope] = new $scope();
        }

        throw new InvalidArgumentException('Global scope must be an instance of Closure or Scope or be a class name of a class extending ' . Scope::class);
    }

    /**
     * Register multiple global scopes on the model.
     */
    public static function addGlobalScopes(array $scopes): void
    {
        foreach ($scopes as $key => $scope) {
            if (is_string($key)) {
                static::addGlobalScope($key, $scope);
            } else {
                static::addGlobalScope($scope);
            }
        }
    }

    /**
     * Determine if a model has a global scope.
     */
    public static function hasGlobalScope(Scope|string $scope): bool
    {
        return ! is_null(static::getGlobalScope($scope));
    }

    /**
     * Get a global scope registered with the model.
     *
     * @return null|(Closure(\Hypervel\Database\Eloquent\Builder<static>): mixed)|\Hypervel\Database\Eloquent\Scope
     */
    public static function getGlobalScope(Scope|string $scope): Scope|Closure|null
    {
        if (is_string($scope)) {
            return Arr::get(static::$globalScopes, static::class . '.' . $scope);
        }

        return Arr::get(
            static::$globalScopes,
            static::class . '.' . get_class($scope)
        );
    }

    /**
     * Get all of the global scopes that are currently registered.
     */
    public static function getAllGlobalScopes(): array
    {
        return static::$globalScopes;
    }

    /**
     * Set the current global scopes.
     */
    public static function setAllGlobalScopes(array $scopes): void
    {
        static::$globalScopes = $scopes;
    }

    /**
     * Get the global scopes for this class instance.
     */
    public function getGlobalScopes(): array
    {
        return Arr::get(static::$globalScopes, static::class, []);
    }
}
