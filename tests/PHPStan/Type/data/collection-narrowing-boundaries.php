<?php

namespace CollectionNarrowingBoundaries;

use App\ModelWithOnlyValueGenericCollection;
use App\OnlyValueGenericCollection;
use Hypervel\Support\Collection;
use Hypervel\Support\LazyCollection;

use function PHPStan\Testing\assertType;

/**
 * Upstream narrowed a template's bound, which dropped the template from generic code.
 *
 * @template TValue of int|null
 *
 * @param Collection<int, TValue> $values
 */
function templateValues(Collection $values): void
{
    assertType('Hypervel\Support\Collection<int, TValue of int|null (function CollectionNarrowingBoundaries\templateValues(), argument)>', $values->filter());
    assertType('Hypervel\Support\Collection<int, TValue of int|null (function CollectionNarrowingBoundaries\templateValues(), argument)>', $values->reject(fn ($value) => $value === null));
    assertType('Hypervel\Support\Collection<int, TValue of int|null (function CollectionNarrowingBoundaries\templateValues(), argument)>', $values->whereNotNull());
}

/**
 * Upstream rebuilt a union of collections as its first collection class.
 *
 * @param Collection<int, int|null>|LazyCollection<int, int|null> $values
 */
function unionOfCollections(Collection|LazyCollection $values): void
{
    assertType('Hypervel\Support\Collection<int, int|null>|Hypervel\Support\LazyCollection<int, int|null>', $values->filter());
    assertType('Hypervel\Support\Collection<int, int|null>|Hypervel\Support\LazyCollection<int, int|null>', $values->whereNotNull());
}

/**
 * Upstream rebuilt a collection with one template as if it took a key and a value.
 *
 * @param OnlyValueGenericCollection<ModelWithOnlyValueGenericCollection> $models
 */
function oneTemplate(OnlyValueGenericCollection $models): void
{
    assertType('App\OnlyValueGenericCollection<App\ModelWithOnlyValueGenericCollection>', $models->filter());
    assertType('App\OnlyValueGenericCollection<App\ModelWithOnlyValueGenericCollection>', $models->whereNotNull());
}

/**
 * @template TKey of array-key
 * @template TValue
 *
 * @extends Collection<TKey, TValue>
 */
class KeepingCollection extends Collection
{
    public function filter(?callable $callback = null): static
    {
        return $this;
    }
}

/**
 * @template TKey of array-key
 * @template TValue
 *
 * @extends Collection<TKey, TValue>
 */
class KeepingWhereNotNullCollection extends Collection
{
    public function whereNotNull(?string $key = null): static
    {
        return $this;
    }
}

/**
 * A collection that overrides a narrowing method, or one that it runs, may keep other values.
 *
 * @param KeepingCollection<int, int|null> $keeping
 * @param KeepingWhereNotNullCollection<int, int|null> $keepingWhereNotNull
 */
function customImplementations(KeepingCollection $keeping, KeepingWhereNotNullCollection $keepingWhereNotNull): void
{
    assertType('CollectionNarrowingBoundaries\KeepingCollection<int, int|null>', $keeping->filter());
    assertType('CollectionNarrowingBoundaries\KeepingCollection<int, int|null>', $keeping->reject(fn ($value) => $value === null));
    assertType('CollectionNarrowingBoundaries\KeepingCollection<int, int|null>', $keeping->where(fn ($value) => $value !== null));
    assertType('CollectionNarrowingBoundaries\KeepingCollection<int, int|null>', $keeping->whereNotNull());
    assertType('CollectionNarrowingBoundaries\KeepingWhereNotNullCollection<int, int|null>', $keepingWhereNotNull->whereNotNull());
    assertType('CollectionNarrowingBoundaries\KeepingWhereNotNullCollection<int, int>', $keepingWhereNotNull->filter(fn ($value) => $value !== null));
}

/**
 * Hypervel: callbacks receive the key as well as the value, and only callables that are not strings narrow reject() and where().
 *
 * @param Collection<int|string, int|null> $values
 * @param LazyCollection<int|string, int|null> $lazyValues
 */
function callbackArguments(Collection $values, LazyCollection $lazyValues): void
{
    assertType('Hypervel\Support\Collection<string, int|null>', $values->filter(fn ($value, $key) => is_string($key)));
    assertType('Hypervel\Support\LazyCollection<int, int|null>', $lazyValues->reject(fn ($value, $key) => is_string($key)));
    assertType('Hypervel\Support\Collection<int|string, int<min, -1>|int<1, max>>', $values->filter(null));
    assertType('Hypervel\Support\Collection<int|string, int>', $values->where(function ($value) {
        return $value !== null;
    }));
    assertType('Hypervel\Support\Collection<int|string, int|null>', $values->where('id', 1));
    assertType('Hypervel\Support\Collection<int|string, int|null>', $values->reject(null));
}
