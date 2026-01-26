<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent\Concerns;

use Hypervel\Database\Eloquent\Attributes\CollectedBy;
use Hypervel\Database\Eloquent\Collection;
use ReflectionClass;

/**
 * Provides support for custom collection classes on models.
 *
 * Models can specify their collection class in two ways:
 * 1. Using the #[CollectedBy] attribute (takes precedence)
 * 2. Overriding the static $collectionClass property
 *
 * The fallback chain is: #[CollectedBy] attribute → $collectionClass property.
 */
trait HasCollection
{
    /**
     * The resolved collection class names by model.
     *
     * @var array<class-string, class-string<Collection>>
     */
    protected static array $resolvedCollectionClasses = [];

    /**
     * Create a new Eloquent Collection instance.
     *
     * @param array<array-key, static> $models
     * @return \Hypervel\Database\Eloquent\Collection<array-key, static>
     */
    public function newCollection(array $models = []): Collection
    {
        static::$resolvedCollectionClasses[static::class] ??= ($this->resolveCollectionFromAttribute() ?? static::$collectionClass);

        return new static::$resolvedCollectionClasses[static::class]($models);
    }

    /**
     * Resolve the collection class name from the CollectedBy attribute.
     *
     * @return null|class-string<Collection>
     */
    protected function resolveCollectionFromAttribute(): ?string
    {
        $reflectionClass = new ReflectionClass(static::class);

        $attributes = $reflectionClass->getAttributes(CollectedBy::class);

        if (! isset($attributes[0])) {
            return null;
        }

        // @phpstan-ignore return.type (attribute stores generic Model type, but we know it's compatible with static)
        return $attributes[0]->newInstance()->collectionClass;
    }
}
