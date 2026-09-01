<?php

declare(strict_types=1);

namespace Hypervel\Data\Concerns;

use Hypervel\Container\Container;
use Hypervel\Contracts\Pagination\CursorPaginator as CursorPaginatorContract;
use Hypervel\Contracts\Pagination\Paginator as PaginatorContract;
use Hypervel\Data\CursorPaginatedDataCollection;
use Hypervel\Data\DataCollection;
use Hypervel\Data\PaginatedDataCollection;
use Hypervel\Data\Support\Creation\CreationContextFactory;
use Hypervel\Database\Eloquent\Collection as EloquentCollection;
use Hypervel\Http\Request;
use Hypervel\Pagination\AbstractCursorPaginator;
use Hypervel\Pagination\AbstractPaginator;
use Hypervel\Support\Collection;
use Hypervel\Support\Enumerable;
use Hypervel\Support\LazyCollection;

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
     * @template TKey of array-key
     * @template TValue
     *
     * @param AbstractCursorPaginator|AbstractPaginator|array<TKey, TValue>|Collection<TKey, TValue>|CursorPaginatorContract|DataCollection<TKey, TValue>|EloquentCollection<TKey, TValue>|Enumerable|LazyCollection<TKey, TValue>|PaginatorContract $items
     */
    public static function collect(mixed $items, ?string $into = null): array|DataCollection|PaginatedDataCollection|CursorPaginatedDataCollection|Enumerable|AbstractPaginator|PaginatorContract|AbstractCursorPaginator|CursorPaginatorContract|LazyCollection|Collection
    {
        return static::factory()->collect($items, $into);
    }

    /**
     * Create a fresh data construction factory.
     *
     * @return CreationContextFactory<static>
     */
    public static function factory(): CreationContextFactory
    {
        /** @var CreationContextFactory<static> $factory */
        $factory = Container::getInstance()->make(
            CreationContextFactory::class,
            ['dataClass' => static::class],
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

    /**
     * Create a data object from the current request.
     */
    public static function newInstance(Request $request): static
    {
        return static::from($request);
    }
}
