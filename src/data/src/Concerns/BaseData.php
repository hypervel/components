<?php

declare(strict_types=1);

namespace Hypervel\Data\Concerns;

use Hypervel\Container\Container;
use Hypervel\Contracts\Pagination\CursorPaginator as CursorPaginatorContract;
use Hypervel\Contracts\Pagination\LengthAwarePaginator as LengthAwarePaginatorContract;
use Hypervel\Contracts\Pagination\Paginator as PaginatorContract;
use Hypervel\Data\Contracts\BaseData as BaseDataContract;
use Hypervel\Data\CursorPaginatedDataCollection;
use Hypervel\Data\DataCollection;
use Hypervel\Data\PaginatedDataCollection;
use Hypervel\Data\Support\Creation\CreationContextFactory;
use Hypervel\Data\Support\Creation\DataCreator;
use Hypervel\Data\Support\DataConfig;
use Hypervel\Database\Eloquent\Collection as EloquentCollection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Http\Request;
use Hypervel\Pagination\AbstractCursorPaginator;
use Hypervel\Pagination\AbstractPaginator;
use Hypervel\Pagination\CursorPaginator;
use Hypervel\Pagination\LengthAwarePaginator;
use Hypervel\Pagination\Paginator;
use Hypervel\Support\Collection;
use Hypervel\Support\Enumerable;
use Hypervel\Support\LazyCollection;
use Traversable;

trait BaseData
{
    /**
     * Create an optional data object.
     */
    public static function optional(mixed ...$payloads): ?static
    {
        if (count($payloads) === 0) {
            return null;
        }

        foreach ($payloads as $payload) {
            if ($payload !== null) {
                return static::from(...$payloads);
            }
        }

        return null;
    }

    /**
     * Create a data object.
     */
    public static function from(mixed ...$payloads): static
    {
        return static::factory()->from(...$payloads);
    }

    /**
     * Collect data objects.
     *
     * Contract-typed sources retain every possible rebuildable runtime shape.
     *
     * @template TKey of array-key
     * @template TValue
     * @template TCollectValue of BaseDataContract
     * @template TModelValue of Model
     *
     * @param AbstractCursorPaginator<TKey, TValue>|AbstractPaginator<TKey, TValue>|array<TKey, TValue>|Collection<TKey, TValue>|CursorPaginatedDataCollection<TKey, TCollectValue>|CursorPaginatorContract<TKey, TValue>|DataCollection<TKey, TCollectValue>|EloquentCollection<TKey, TModelValue>|Enumerable<TKey, TValue>|LazyCollection<TKey, TValue>|LengthAwarePaginatorContract<TKey, TValue>|PaginatedDataCollection<TKey, TCollectValue>|PaginatorContract<TKey, TValue>|Traversable<TKey, TValue> $items
     * @param null|'array'|class-string $into
     * @return (
     *     $into is null
     *     ? ($items is array
     *         ? array<TKey, static>
     *         : ($items is PaginatedDataCollection<*, *>|CursorPaginatedDataCollection<*, *>|DataCollection<*, *>
     *             ? ($items is PaginatedDataCollection<*, *>
     *                 ? PaginatedDataCollection<TKey, static>
     *                 : ($items is CursorPaginatedDataCollection<*, *>
     *                     ? CursorPaginatedDataCollection<TKey, static>
     *                     : DataCollection<TKey, static>))
     *             : ($items is AbstractPaginator<*, *>
     *                 ? ($items is LengthAwarePaginator<*, *>
     *                     ? LengthAwarePaginator<TKey, static>
     *                     : ($items is Paginator<*, *>
     *                         ? Paginator<TKey, static>
     *                         : AbstractPaginator<TKey, static>))
     *                 : ($items is AbstractCursorPaginator<*, *>
     *                     ? ($items is CursorPaginator<*, *>
     *                         ? CursorPaginator<TKey, static>
     *                         : AbstractCursorPaginator<TKey, static>)
     *                     : ($items is Enumerable<*, *>
     *                         ? ($items is EloquentCollection<*, *>
     *                             ? Collection<TKey, static>
     *                             : ($items is LazyCollection<*, *>
     *                                 ? LazyCollection<TKey, static>
     *                                 : ($items is Collection<*, *>
     *                                     ? Collection<TKey, static>
     *                                     : never)))
     *                         : never)))))
     *     : ($into is 'array'
     *         ? array<TKey, static>
     *         : ($into is 'Hypervel\Support\Enumerable'|'Hypervel\Database\Eloquent\Collection'|'Hypervel\Support\Collection'
     *             ? Collection<TKey, static>
     *             : ($into is 'Hypervel\Support\LazyCollection'
     *                 ? LazyCollection<TKey, static>
     *                 : ($into is 'Hypervel\Data\PaginatedDataCollection'|'Hypervel\Data\CursorPaginatedDataCollection'|'Hypervel\Data\DataCollection'
     *                     ? ($into is 'Hypervel\Data\PaginatedDataCollection'
     *                         ? PaginatedDataCollection<TKey, static>
     *                         : ($into is 'Hypervel\Data\CursorPaginatedDataCollection'
     *                             ? CursorPaginatedDataCollection<TKey, static>
     *                             : DataCollection<TKey, static>))
     *                     : ($into is 'Hypervel\Pagination\LengthAwarePaginator'|'Hypervel\Pagination\Paginator'|'Hypervel\Pagination\CursorPaginator'|'Hypervel\Pagination\AbstractPaginator'|'Hypervel\Pagination\AbstractCursorPaginator'
     *                         ? ($into is 'Hypervel\Pagination\LengthAwarePaginator'
     *                             ? LengthAwarePaginator<TKey, static>
     *                             : ($into is 'Hypervel\Pagination\Paginator'
     *                                 ? Paginator<TKey, static>
     *                                 : ($into is 'Hypervel\Pagination\CursorPaginator'
     *                                     ? CursorPaginator<TKey, static>
     *                                     : ($into is 'Hypervel\Pagination\AbstractPaginator'
     *                                         ? AbstractPaginator<TKey, static>
     *                                         : AbstractCursorPaginator<TKey, static>))))
     *                         : ($into is 'Hypervel\Contracts\Pagination\LengthAwarePaginator'|'Hypervel\Contracts\Pagination\Paginator'|'Hypervel\Contracts\Pagination\CursorPaginator'
     *                             ? ($into is 'Hypervel\Contracts\Pagination\LengthAwarePaginator'
     *                                 ? LengthAwarePaginatorContract<TKey, static>
     *                                 : ($into is 'Hypervel\Contracts\Pagination\Paginator'
     *                                     ? PaginatorContract<TKey, static>
     *                                     : CursorPaginatorContract<TKey, static>))
     *                             : array<TKey, static>|CursorPaginatedDataCollection<TKey, static>|DataCollection<TKey, static>|PaginatedDataCollection<TKey, static>|Enumerable<TKey, static>|AbstractCursorPaginator<TKey, static>|AbstractPaginator<TKey, static>|CursorPaginatorContract<TKey, static>|LengthAwarePaginatorContract<TKey, static>|PaginatorContract<TKey, static>)))))))
     * )
     */
    public static function collect(mixed $items, ?string $into = null): array|DataCollection|PaginatedDataCollection|CursorPaginatedDataCollection|Enumerable|AbstractPaginator|PaginatorContract|AbstractCursorPaginator|CursorPaginatorContract|LazyCollection|Collection
    {
        return static::factory()->collect($items, $into);
    }

    // REMOVED: Deprecated collection() and Enumerable forwarding; use collect() and toCollection().
    // REMOVED: Factories cannot inherit mutable in-flight creation contexts; every call starts fresh.

    /**
     * Create a fresh data construction factory.
     *
     * @return CreationContextFactory<static>
     */
    public static function factory(): CreationContextFactory
    {
        $container = Container::getInstance();

        /** @var CreationContextFactory<static> $factory */
        $factory = new CreationContextFactory(
            $container->make(DataCreator::class),
            $container->make(DataConfig::class),
            static::class,
        );

        return $factory;
    }

    /**
     * Get the class-owned data normalizers.
     */
    public static function normalizers(): array
    {
        return [];
    }

    // REMOVED: Configurable pipelines and prepareForPipeline(); use named factories and factory hooks.

    /**
     * Create a data object from the current request.
     */
    public static function newInstance(Request $request): static
    {
        return static::from($request);
    }
}
