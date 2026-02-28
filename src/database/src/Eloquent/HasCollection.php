<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent;

use Hypervel\Database\Eloquent\Attributes\CollectedBy;
use ReflectionClass;

/**
 * @template TCollection of \Hypervel\Database\Eloquent\Collection
 */
trait HasCollection
{
    /**
     * The resolved collection class names by model.
     *
     * @var array<class-string<static>, class-string<TCollection>>
     */
    protected static array $resolvedCollectionClasses = [];

    /**
     * Create a new Eloquent Collection instance.
     *
     * @param array<array-key, Model> $models
     * @return TCollection
     */
    public function newCollection(array $models = []): Collection
    {
        // @phpstan-ignore assign.propertyType (generic type narrowing loss with static property)
        static::$resolvedCollectionClasses[static::class] ??= ($this->resolveCollectionFromAttribute() ?? static::$collectionClass);

        $collection = new static::$resolvedCollectionClasses[static::class]($models);

        if (Model::isAutomaticallyEagerLoadingRelationships()) {
            $collection->withRelationshipAutoloading();
        }

        // @phpstan-ignore return.type (dynamic class instantiation from static property loses generic type)
        return $collection;
    }

    /**
     * Resolve the collection class name from the CollectedBy attribute.
     *
     * @return null|class-string<TCollection>
     */
    public function resolveCollectionFromAttribute(): ?string
    {
        $reflectionClass = new ReflectionClass(static::class);

        $attributes = $reflectionClass->getAttributes(CollectedBy::class);

        if (! isset($attributes[0]) || ! isset($attributes[0]->getArguments()[0])) {
            return null;
        }

        return $attributes[0]->getArguments()[0];
    }
}
