<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent\Concerns;

use Hypervel\Database\Eloquent\Attributes\CollectedBy;
use Hypervel\Database\Eloquent\Collection;
use ReflectionClass;

/**
 * Provides support for the CollectedBy attribute on models.
 *
 * This trait allows models to declare their collection class using the
 * #[CollectedBy] attribute instead of overriding the newCollection method.
 */
trait HasCollection
{
    /**
     * The resolved collection class names by model.
     *
     * @var array<class-string<static>, class-string<Collection<array-key, static>>>
     */
    protected static array $resolvedCollectionClasses = [];

    /**
     * Create a new Eloquent Collection instance.
     *
     * @param array<array-key, static> $models
     * @return Collection<array-key, static>
     */
    public function newCollection(array $models = []): Collection
    {
        static::$resolvedCollectionClasses[static::class] ??= ($this->resolveCollectionFromAttribute() ?? Collection::class);

        return new static::$resolvedCollectionClasses[static::class]($models);
    }

    /**
     * Resolve the collection class name from the CollectedBy attribute.
     *
     * @return null|class-string<Collection<array-key, static>>
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
