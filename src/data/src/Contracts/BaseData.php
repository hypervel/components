<?php

declare(strict_types=1);

namespace Hypervel\Data\Contracts;

use Hypervel\Contracts\Container\SelfBuilding;
use Hypervel\Contracts\Pagination\CursorPaginator as CursorPaginatorContract;
use Hypervel\Contracts\Pagination\Paginator as PaginatorContract;
use Hypervel\Data\CursorPaginatedDataCollection;
use Hypervel\Data\DataCollection;
use Hypervel\Data\Normalizers\Normalizer;
use Hypervel\Data\PaginatedDataCollection;
use Hypervel\Data\Support\Creation\CreationContextFactory;
use Hypervel\Database\Eloquent\Collection as EloquentCollection;
use Hypervel\Pagination\AbstractCursorPaginator;
use Hypervel\Pagination\AbstractPaginator;
use Hypervel\Pagination\CursorPaginator;
use Hypervel\Pagination\Paginator;
use Hypervel\Support\Collection;
use Hypervel\Support\Enumerable;
use Hypervel\Support\LazyCollection;

/**
 * @template TData
 * @template TValue of mixed
 * @template TKey of array-key
 */
interface BaseData extends SelfBuilding
{
    /**
     * Create an optional data object.
     */
    public static function optional(mixed ...$payloads): ?static;

    /**
     * Create a data object.
     */
    public static function from(mixed ...$payloads): static;

    /**
     * Collect data objects.
     *
     * @param AbstractCursorPaginator|AbstractPaginator|array<TKey, TValue>|Collection<TKey, TValue>|CursorPaginatorContract|DataCollection<TKey, TValue>|EloquentCollection<TKey, TValue>|Enumerable|LazyCollection<TKey, TValue>|PaginatorContract $items
     *
     * @return ($into is 'array' ? array<TKey, static> : ($into is class-string<EloquentCollection> ? Collection<TKey, static> : ($into is class-string<Collection> ? Collection<TKey, static> : ($into is class-string<LazyCollection> ? LazyCollection<TKey, static> : ($into is class-string<DataCollection> ? DataCollection<TKey, static> : ($into is class-string<PaginatedDataCollection> ? PaginatedDataCollection<TKey, static> : ($into is class-string<CursorPaginatedDataCollection> ? CursorPaginatedDataCollection<TKey, static> : ($items is EloquentCollection ? Collection<TKey, static> : ($items is Collection ? Collection<TKey, static> : ($items is LazyCollection ? LazyCollection<TKey, static> : ($items is Enumerable ? Enumerable<TKey, static> : ($items is array ? array<TKey, static> : ($items is AbstractPaginator ? AbstractPaginator : ($items is PaginatorContract ? PaginatorContract : ($items is AbstractCursorPaginator ? AbstractCursorPaginator : ($items is CursorPaginatorContract ? CursorPaginatorContract : ($items is DataCollection ? DataCollection<TKey, static> : ($items is CursorPaginator ? CursorPaginatedDataCollection<TKey, static> : ($items is Paginator ? PaginatedDataCollection<TKey, static> : DataCollection<TKey, static>)))))))))))))))))))
     */
    public static function collect(mixed $items, ?string $into = null): array|DataCollection|PaginatedDataCollection|CursorPaginatedDataCollection|Enumerable|AbstractPaginator|PaginatorContract|AbstractCursorPaginator|CursorPaginatorContract|LazyCollection|Collection;

    /**
     * Create a data construction factory.
     *
     * @return CreationContextFactory<static>
     */
    public static function factory(): CreationContextFactory;

    /**
     * Get the data normalizers.
     *
     * @return list<class-string<Normalizer>>
     */
    public static function normalizers(): array;
}
