<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent\Concerns;

use Closure;
use Hyperf\Collection\Collection;
use Hyperf\Database\Model\GlobalScope;
use Hyperf\Database\Model\Model as HyperfModel;
use Hyperf\Database\Model\Scope;
use Hypervel\Database\Eloquent\Attributes\ScopedBy;
use InvalidArgumentException;
use ReflectionAttribute;
use ReflectionClass;

/**
 * Extends Hyperf's global scope functionality with attribute-based registration.
 *
 * This trait adds support for the #[ScopedBy] attribute, allowing models
 * to declare their global scopes declaratively on the class or traits.
 */
trait HasGlobalScopes
{
    /**
     * Boot the has global scopes trait for a model.
     *
     * Automatically registers any global scopes declared via the ScopedBy attribute.
     */
    public static function bootHasGlobalScopes(): void
    {
        $scopes = static::resolveGlobalScopeAttributes();

        if (! empty($scopes)) {
            static::addGlobalScopes($scopes);
        }
    }

    /**
     * Resolve the global scope class names from the ScopedBy attributes.
     *
     * Collects ScopedBy attributes from parent classes, traits, and the
     * current class itself, merging them together. The order is:
     * parent class scopes -> trait scopes -> class scopes.
     *
     * @return array<int, class-string<Scope>>
     */
    public static function resolveGlobalScopeAttributes(): array
    {
        $reflectionClass = new ReflectionClass(static::class);

        $parentClass = get_parent_class(static::class);
        $hasParentWithMethod = $parentClass
            && $parentClass !== HyperfModel::class
            && method_exists($parentClass, 'resolveGlobalScopeAttributes');

        // Collect attributes from traits, then from the class itself
        $attributes = new Collection();

        foreach ($reflectionClass->getTraits() as $trait) {
            foreach ($trait->getAttributes(ScopedBy::class, ReflectionAttribute::IS_INSTANCEOF) as $attribute) {
                $attributes->push($attribute);
            }
        }

        foreach ($reflectionClass->getAttributes(ScopedBy::class, ReflectionAttribute::IS_INSTANCEOF) as $attribute) {
            $attributes->push($attribute);
        }

        // Process all collected attributes
        $scopes = $attributes
            ->map(fn (ReflectionAttribute $attribute) => $attribute->getArguments())
            ->flatten();

        // Prepend parent's scopes if applicable
        return $scopes
            ->when($hasParentWithMethod, function (Collection $attrs) use ($parentClass) {
                /** @var class-string $parentClass */
                return (new Collection($parentClass::resolveGlobalScopeAttributes()))
                    ->merge($attrs);
            })
            ->all();
    }

    /**
     * Register multiple global scopes on the model.
     *
     * @param array<int|string, class-string<Scope>|Closure|Scope> $scopes
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
     * Register a new global scope on the model.
     *
     * Extends Hyperf's implementation to support scope class-strings.
     *
     * @param Closure|Scope|string $scope
     * @return mixed
     *
     * @throws InvalidArgumentException
     */
    public static function addGlobalScope($scope, ?Closure $implementation = null)
    {
        if (is_string($scope) && $implementation !== null) {
            return GlobalScope::$container[static::class][$scope] = $implementation;
        }

        if ($scope instanceof Closure) {
            return GlobalScope::$container[static::class][spl_object_hash($scope)] = $scope;
        }

        if ($scope instanceof Scope) {
            return GlobalScope::$container[static::class][get_class($scope)] = $scope;
        }

        // Support class-string for Scope classes (Laravel compatibility)
        if (class_exists($scope) && is_subclass_of($scope, Scope::class)) {
            return GlobalScope::$container[static::class][$scope] = new $scope();
        }

        throw new InvalidArgumentException(
            'Global scope must be an instance of Closure or Scope, or a class-string of a Scope implementation.'
        );
    }
}
