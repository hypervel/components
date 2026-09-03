<?php

declare(strict_types=1);

namespace Hypervel\Data;

use ArrayAccess;
use Countable;
use Hypervel\Contracts\Container\Transient;
use Hypervel\Contracts\Database\Eloquent\Castable as EloquentCastable;
use Hypervel\Contracts\Database\Eloquent\CastsAttributes;
use Hypervel\Contracts\Database\Eloquent\CastsInboundAttributes;
use Hypervel\Data\Concerns\BaseDataCollectable as BaseDataCollectableConcern;
use Hypervel\Data\Concerns\IncludeableData as IncludeableDataConcern;
use Hypervel\Data\Concerns\ResponsableData as ResponsableDataConcern;
use Hypervel\Data\Concerns\TransformableData as TransformableDataConcern;
use Hypervel\Data\Concerns\WrappableData as WrappableDataConcern;
use Hypervel\Data\Contracts\BaseData as BaseDataContract;
use Hypervel\Data\Contracts\BaseDataCollectable as BaseDataCollectableContract;
use Hypervel\Data\Contracts\IncludeableData as IncludeableDataContract;
use Hypervel\Data\Contracts\ResponsableData as ResponsableDataContract;
use Hypervel\Data\Contracts\TransformableData as TransformableDataContract;
use Hypervel\Data\Contracts\WrappableData as WrappableDataContract;
use Hypervel\Data\Eloquent\DataCollectionEloquentCast;
use Hypervel\Data\Exceptions\CannotCastData;
use Hypervel\Data\Exceptions\InvalidDataCollectionOperation;
use Hypervel\Support\Enumerable;
use Hypervel\Support\Traits\Macroable;

/**
 * @template TKey of array-key
 * @template TValue of BaseDataContract
 *
 * @implements ArrayAccess<TKey, TValue>
 * @implements BaseDataCollectableContract<TKey, TValue>
 */
class DataCollection implements BaseDataCollectableContract, TransformableDataContract, IncludeableDataContract, ResponsableDataContract, WrappableDataContract, EloquentCastable, Countable, ArrayAccess, Transient
{
    /** @use BaseDataCollectableConcern<TKey, TValue> */
    use BaseDataCollectableConcern;

    use IncludeableDataConcern;
    use ResponsableDataConcern;
    use TransformableDataConcern;
    use WrappableDataConcern;
    use Macroable;

    /** @var Enumerable<TKey, TValue> */
    protected Enumerable $items;

    /**
     * Create a typed data collection.
     *
     * @param class-string<TValue> $dataClass
     * @param null|array<TKey, mixed>|DataCollection<TKey, BaseDataContract>|Enumerable<TKey, mixed> $items
     */
    public function __construct(
        public readonly string $dataClass,
        Enumerable|array|DataCollection|null $items,
    ) {
        if ($items instanceof DataCollection) {
            $items = $items->toCollection();
        }

        $this->items = $this->dataClass::factory()->collectItems($items);
    }

    /**
     * Get all data items.
     *
     * @return array<TKey, TValue>
     */
    public function items(): array
    {
        return $this->items->all();
    }

    /**
     * Get the underlying item collection.
     *
     * @return Enumerable<TKey, TValue>
     */
    public function toCollection(): Enumerable
    {
        return $this->items;
    }

    /**
     * Get the number of data items.
     */
    public function count(): int
    {
        return $this->items->count();
    }

    /**
     * Determine if an item exists at the given offset.
     *
     * @param TKey $offset
     */
    public function offsetExists(mixed $offset): bool
    {
        if (! $this->items instanceof ArrayAccess) {
            throw InvalidDataCollectionOperation::create();
        }

        return $this->items->offsetExists($offset);
    }

    /**
     * Get an item at the given offset.
     *
     * @param TKey $offset
     *
     * @return TValue
     */
    public function offsetGet(mixed $offset): mixed
    {
        if (! $this->items instanceof ArrayAccess) {
            throw InvalidDataCollectionOperation::create();
        }

        return $this->items->offsetGet($offset);
    }

    /**
     * Set the item at the given offset.
     *
     * @param null|TKey $offset
     * @param TValue $value
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        if (! $this->items instanceof ArrayAccess) {
            throw InvalidDataCollectionOperation::create();
        }

        $value = $value instanceof $this->dataClass
            ? $value
            : $this->dataClass::factory()->from($value);

        $this->items->offsetSet($offset, $value);
    }

    /**
     * Unset the item at the given offset.
     *
     * @param TKey $offset
     */
    public function offsetUnset(mixed $offset): void
    {
        if (! $this->items instanceof ArrayAccess) {
            throw InvalidDataCollectionOperation::create();
        }

        $this->items->offsetUnset($offset);
    }

    /**
     * Get the Eloquent caster for the data collection.
     */
    public static function castUsing(array $arguments): CastsAttributes|CastsInboundAttributes|string
    {
        if ($arguments === []) {
            throw CannotCastData::dataCollectionTypeRequired();
        }

        return new DataCollectionEloquentCast($arguments[0], static::class, array_slice($arguments, 1));
    }

    /**
     * Get the underlying items without transforming them.
     *
     * @return Enumerable<TKey, TValue>
     */
    protected function itemsForIteration(): iterable
    {
        return $this->items;
    }
}
