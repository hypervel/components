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
use Hypervel\Data\Normalizers\Normalized\UnknownProperty;
use Hypervel\Data\Optional;
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
     * Rebuild normalized items in the source shape used by collection factories.
     *
     * Contract-only paginators intentionally have no method-dispatch shape because
     * their metadata cannot be cloned without mutating an unknown implementation.
     *
     * @param class-string<BaseData> $dataClass
     * @param array<array-key, BaseData>|Enumerable<array-key, BaseData> $items
     */
    public function forMethodSource(
        string $dataClass,
        mixed $source,
        array|Enumerable $items,
    ): mixed {
        if (! $this->canRebuildRoot($source)) {
            return null;
        }

        return $this->rebuildRoot($dataClass, $source, $items);
    }

    /**
     * Rebuild normalized items in an explicit or source-inferred target.
     *
     * @param class-string<BaseData> $dataClass
     * @param array<array-key, BaseData>|Enumerable<array-key, BaseData> $items
     */
    public function forTarget(
        string $dataClass,
        mixed $source,
        array|Enumerable $items,
        ?string $into,
    ): mixed {
        if ($into === null) {
            if (! $this->canRebuildRoot($source)) {
                throw CannotCreateDataCollectable::create(
                    get_debug_type($source),
                    'inferred collection',
                );
            }

            return $this->rebuildRoot($dataClass, $source, $items);
        }

        if ($into === 'array') {
            return $this->eagerItems($items);
        }

        if ($into === Enumerable::class || is_a($into, EloquentCollection::class, true)) {
            return new Collection($this->eagerItems($items));
        }

        if (is_a($into, DataCollection::class, true)) {
            return new $into($dataClass, $items);
        }

        if (is_a($into, PaginatedDataCollection::class, true)) {
            return new $into(
                $dataClass,
                $this->rebuildPaginator($source, $items, AbstractPaginator::class),
            );
        }

        if (is_a($into, CursorPaginatedDataCollection::class, true)) {
            return new $into(
                $dataClass,
                $this->rebuildCursorPaginator($source, $items, AbstractCursorPaginator::class),
            );
        }

        if (is_a($into, AbstractPaginator::class, true)
            || is_a($into, PaginatorContract::class, true)
        ) {
            return $this->rebuildPaginator($source, $items, $into);
        }

        if (is_a($into, AbstractCursorPaginator::class, true)
            || is_a($into, CursorPaginatorContract::class, true)
        ) {
            return $this->rebuildCursorPaginator($source, $items, $into);
        }

        if (is_a($into, LazyCollection::class, true)) {
            return $items instanceof $into ? $items : new $into($items);
        }

        if (is_a($into, Collection::class, true)) {
            return new $into($this->eagerItems($items));
        }

        throw CannotCreateDataCollectable::create(get_debug_type($source), $into);
    }

    /**
     * Retain the source needed to rebuild one paginated property.
     */
    public function retainPaginatorSource(
        NamedType $type,
        mixed $value,
        ConstructionState $state,
    ): void {
        $sourceClass = match (true) {
            $type->kind->isPaginator() => AbstractPaginator::class,
            $type->kind->isCursorPaginator() => AbstractCursorPaginator::class,
            default => null,
        };

        if ($sourceClass === null) {
            return;
        }

        $source = match (true) {
            $value instanceof PaginatedDataCollection,
            $value instanceof CursorPaginatedDataCollection => $value->items(),
            $value instanceof AbstractPaginator,
            $value instanceof AbstractCursorPaginator => $value,
            default => null,
        };

        if ($source instanceof $sourceClass) {
            $state->recordPaginatorSource($source);

            return;
        }

        if ($value instanceof UnknownProperty || $value instanceof Optional || $value === null) {
            $state->clearPaginatorSource();

            return;
        }

        if ($state->paginatorSource() instanceof $sourceClass
            && (is_array($value) || $value instanceof DataCollection || $value instanceof Enumerable)
        ) {
            return;
        }

        throw CannotCreateDataCollectable::create(get_debug_type($value), $type->name);
    }

    /**
     * Rebuild typed items in a property's declared container.
     *
     * @param array<array-key, mixed> $items
     */
    public function forProperty(
        NamedType $type,
        array $items,
        ConstructionState $state,
    ): mixed {
        return match ($type->kind) {
            DataTypeKind::Array,
            DataTypeKind::Iterable,
            DataTypeKind::DataArray,
            DataTypeKind::DataIterable => $items,
            DataTypeKind::Enumerable,
            DataTypeKind::DataEnumerable => $this->newEnumerable($type->name, $items),
            DataTypeKind::DataCollection => new $type->name($type->dataClass, $items),
            DataTypeKind::DataPaginatedCollection => new $type->name(
                $type->dataClass,
                $this->paginator($type, $items, $state),
            ),
            DataTypeKind::DataCursorPaginatedCollection => new $type->name(
                $type->dataClass,
                $this->cursorPaginator($type, $items, $state),
            ),
            DataTypeKind::Paginator,
            DataTypeKind::DataPaginator => $this->paginator($type, $items, $state),
            DataTypeKind::CursorPaginator,
            DataTypeKind::DataCursorPaginator => $this->cursorPaginator($type, $items, $state),
            default => throw CannotCreateDataCollectable::create('array', $type->name),
        };
    }

    /**
     * Rebuild an eager enumerable in its declared collection class.
     *
     * @param class-string|literal-string $class
     * @param array<array-key, mixed> $items
     */
    protected function newEnumerable(string $class, array $items): Enumerable
    {
        if ($class === Enumerable::class) {
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
     * @param array<array-key, mixed> $items
     */
    protected function paginator(
        NamedType $type,
        array $items,
        ConstructionState $state,
    ): AbstractPaginator {
        $source = $state->paginatorSource();

        if (! $source instanceof AbstractPaginator) {
            throw CannotCreateDataCollectable::missingPaginatorSource($type->name);
        }

        return (clone $source)->setCollection(new Collection($items));
    }

    /**
     * Clone the retained cursor paginator and replace only its items.
     *
     * @param array<array-key, mixed> $items
     */
    protected function cursorPaginator(
        NamedType $type,
        array $items,
        ConstructionState $state,
    ): AbstractCursorPaginator {
        $source = $state->paginatorSource();

        if (! $source instanceof AbstractCursorPaginator) {
            throw CannotCreateDataCollectable::missingPaginatorSource($type->name);
        }

        return (clone $source)->setCollection(new Collection($items));
    }

    /**
     * Rebuild normalized items in their supported root source shape.
     *
     * @param class-string<BaseData> $dataClass
     * @param array<array-key, BaseData>|Enumerable<array-key, BaseData> $items
     */
    protected function rebuildRoot(
        string $dataClass,
        mixed $source,
        array|Enumerable $items,
    ): mixed {
        return match (true) {
            is_array($source) => $this->eagerItems($items),
            $source instanceof PaginatedDataCollection => new $source(
                $dataClass,
                $this->rebuildPaginator($source, $items, AbstractPaginator::class),
            ),
            $source instanceof CursorPaginatedDataCollection => new $source(
                $dataClass,
                $this->rebuildCursorPaginator($source, $items, AbstractCursorPaginator::class),
            ),
            $source instanceof DataCollection => new $source(
                $dataClass,
                $items,
            ),
            $source instanceof AbstractPaginator => $this->rebuildPaginator(
                $source,
                $items,
                $source::class,
            ),
            $source instanceof AbstractCursorPaginator => $this->rebuildCursorPaginator(
                $source,
                $items,
                $source::class,
            ),
            $source instanceof EloquentCollection => new Collection($this->eagerItems($items)),
            $source instanceof LazyCollection => $items instanceof $source
                ? $items
                : new $source($items),
            $source instanceof Collection => new $source($this->eagerItems($items)),
            default => throw CannotCreateDataCollectable::create(
                get_debug_type($source),
                get_debug_type($source),
            ),
        };
    }

    /**
     * Clone an offset paginator source with normalized items.
     *
     * @param array<array-key, BaseData>|Enumerable<array-key, BaseData> $items
     */
    protected function rebuildPaginator(
        mixed $source,
        array|Enumerable $items,
        string $into,
    ): AbstractPaginator {
        if ($source instanceof PaginatedDataCollection) {
            $source = $source->items();
        }

        if (! $source instanceof AbstractPaginator || ! $source instanceof $into) {
            throw CannotCreateDataCollectable::create(get_debug_type($source), $into);
        }

        return (clone $source)->setCollection(new Collection($this->eagerItems($items)));
    }

    /**
     * Clone a cursor paginator source with normalized items.
     *
     * @param array<array-key, BaseData>|Enumerable<array-key, BaseData> $items
     */
    protected function rebuildCursorPaginator(
        mixed $source,
        array|Enumerable $items,
        string $into,
    ): AbstractCursorPaginator {
        if ($source instanceof CursorPaginatedDataCollection) {
            $source = $source->items();
        }

        if (! $source instanceof AbstractCursorPaginator || ! $source instanceof $into) {
            throw CannotCreateDataCollectable::create(get_debug_type($source), $into);
        }

        return (clone $source)->setCollection(new Collection($this->eagerItems($items)));
    }

    /**
     * Materialize normalized items without changing their keys.
     *
     * @param array<array-key, mixed>|Enumerable<array-key, mixed> $items
     * @return array<array-key, mixed>
     */
    protected function eagerItems(array|Enumerable $items): array
    {
        return is_array($items) ? $items : $items->all();
    }

    /**
     * Determine if normalized items can safely retain the root source shape.
     */
    protected function canRebuildRoot(mixed $source): bool
    {
        return is_array($source)
            || $source instanceof DataCollection
            || $source instanceof PaginatedDataCollection
            || $source instanceof CursorPaginatedDataCollection
            || $source instanceof AbstractPaginator
            || $source instanceof AbstractCursorPaginator
            || $source instanceof Collection
            || $source instanceof LazyCollection;
    }
}
