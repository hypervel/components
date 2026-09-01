<?php

declare(strict_types=1);

namespace Hypervel\Data\Support\Creation;

use Hypervel\Contracts\Pagination\CursorPaginator as CursorPaginatorContract;
use Hypervel\Contracts\Pagination\Paginator as PaginatorContract;
use Hypervel\Data\Contracts\BaseData;
use Hypervel\Data\CursorPaginatedDataCollection;
use Hypervel\Data\DataCollection;
use Hypervel\Data\Enums\DataTypeKind;
use Hypervel\Data\Exceptions\CannotCreateDataCollectable;
use Hypervel\Data\PaginatedDataCollection;
use Hypervel\Data\Support\Types\NamedType;
use Hypervel\Database\Eloquent\Collection as EloquentCollection;
use Hypervel\Pagination\AbstractCursorPaginator;
use Hypervel\Pagination\AbstractPaginator;
use Hypervel\Support\Collection;
use Hypervel\Support\Enumerable;
use Hypervel\Support\LazyCollection;
use Traversable;

class DataCollectableFactory
{
    /**
     * Extract keyed items from a supported collection value.
     *
     * @return null|array<array-key, mixed>
     */
    public function items(mixed $value): ?array
    {
        return match (true) {
            $value instanceof DataCollection => $value->items(),
            $value instanceof PaginatedDataCollection,
            $value instanceof CursorPaginatedDataCollection => $value->items()->items(),
            $value instanceof AbstractPaginator,
            $value instanceof AbstractCursorPaginator,
            $value instanceof PaginatorContract,
            $value instanceof CursorPaginatorContract => $value->items(),
            is_array($value) => $value,
            $value instanceof Enumerable => $value->all(),
            $value instanceof Traversable => iterator_to_array($value),
            default => null,
        };
    }

    /**
     * Rebuild typed data items in a property's declared container.
     *
     * @param class-string<BaseData> $dataClass
     * @param array<array-key, BaseData> $items
     */
    public function forProperty(
        NamedType $type,
        string $dataClass,
        array $items,
        ConstructionState $state,
    ): mixed {
        return match ($type->kind) {
            DataTypeKind::DataArray,
            DataTypeKind::DataIterable => $items,
            DataTypeKind::DataEnumerable => $this->newEnumerable($type->name, $items),
            DataTypeKind::DataCollection => new $type->name($dataClass, $items),
            DataTypeKind::DataPaginatedCollection => new $type->name(
                $dataClass,
                $this->paginator($type, $items, $state),
            ),
            DataTypeKind::DataCursorPaginatedCollection => new $type->name(
                $dataClass,
                $this->cursorPaginator($type, $items, $state),
            ),
            DataTypeKind::DataPaginator => $this->paginator($type, $items, $state),
            DataTypeKind::DataCursorPaginator => $this->cursorPaginator($type, $items, $state),
            default => throw CannotCreateDataCollectable::create('array', $type->name),
        };
    }

    /**
     * Rebuild an eager enumerable without retaining an Eloquent model container.
     *
     * @param class-string|literal-string $class
     * @param array<array-key, mixed> $items
     */
    protected function newEnumerable(string $class, array $items): Enumerable
    {
        if ($class === Enumerable::class || is_a($class, EloquentCollection::class, true)) {
            return new Collection($items);
        }

        if (is_a($class, Collection::class, true) || is_a($class, LazyCollection::class, true)) {
            return new $class($items);
        }

        throw CannotCreateDataCollectable::create('array', $class);
    }

    /**
     * Clone the retained paginator and replace only its items.
     *
     * @param array<array-key, BaseData> $items
     */
    protected function paginator(
        NamedType $type,
        array $items,
        ConstructionState $state,
    ): AbstractPaginator {
        $source = $state->paginatorSource();

        if (! $source instanceof AbstractPaginator) {
            throw CannotCreateDataCollectable::create(
                get_debug_type($source),
                $type->name,
            );
        }

        return (clone $source)->setCollection(new Collection($items));
    }

    /**
     * Clone the retained cursor paginator and replace only its items.
     *
     * @param array<array-key, BaseData> $items
     */
    protected function cursorPaginator(
        NamedType $type,
        array $items,
        ConstructionState $state,
    ): AbstractCursorPaginator {
        $source = $state->paginatorSource();

        if (! $source instanceof AbstractCursorPaginator) {
            throw CannotCreateDataCollectable::create(
                get_debug_type($source),
                $type->name,
            );
        }

        return (clone $source)->setCollection(new Collection($items));
    }
}
