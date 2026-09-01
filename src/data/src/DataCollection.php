<?php

declare(strict_types=1);

namespace Hypervel\Data;

use ArrayAccess;
use Countable;
use Hypervel\Contracts\Database\Eloquent\CastsAttributes;
use Hypervel\Contracts\Database\Eloquent\CastsInboundAttributes;
use Hypervel\Data\Concerns\BaseDataCollectable as BaseDataCollectableConcern;
use Hypervel\Data\Concerns\IncludeableData as IncludeableDataConcern;
use Hypervel\Data\Concerns\TransformableData as TransformableDataConcern;
use Hypervel\Data\Concerns\WrappableData as WrappableDataConcern;
use Hypervel\Data\Contracts\BaseData as BaseDataContract;
use Hypervel\Data\Contracts\BaseDataCollectable as BaseDataCollectableContract;
use Hypervel\Data\Contracts\IncludeableData as IncludeableDataContract;
use Hypervel\Data\Contracts\TransformableData as TransformableDataContract;
use Hypervel\Data\Contracts\WrappableData as WrappableDataContract;
use Hypervel\Data\Eloquent\DataCollectionEloquentCast;
use Hypervel\Data\Exceptions\CannotCastData;
use Hypervel\Data\Exceptions\InvalidDataCollectionOperation;
use Hypervel\Support\Collection;
use Hypervel\Support\Enumerable;
use Hypervel\Support\Traits\Macroable;

/**
 * @template TKey of array-key
 * @template TValue of BaseDataContract
 *
 * @implements ArrayAccess<TKey, TValue>
 * @implements BaseDataCollectableContract<TKey, TValue>
 */
class DataCollection implements BaseDataCollectableContract, TransformableDataContract, IncludeableDataContract, WrappableDataContract, Countable, ArrayAccess
{
    /** @use BaseDataCollectableConcern<TKey, TValue> */
    use BaseDataCollectableConcern;
    use IncludeableDataConcern;
    use TransformableDataConcern;
    use WrappableDataConcern;
    use Macroable;

    /** @var Enumerable<TKey, TValue> */
    protected Enumerable $items;

    /**
     * Create a typed data collection.
     *
     * @param class-string<TValue> $dataClass
     * @param array<TKey, mixed>|Enumerable<TKey, mixed>|DataCollection<TKey, BaseDataContract>|null $items
     */
    public function __construct(
        public readonly string $dataClass,
        Enumerable|array|DataCollection|null $items,
    ) {
        if (is_array($items) || $items === null) {
            $items = new Collection($items);
        }

        if ($items instanceof DataCollection) {
            $items = $items->toCollection();
        }

        $factory = $this->dataClass::factory();
        $this->items = $items->map(
            fn (mixed $item): BaseDataContract => $item instanceof $this->dataClass
                ? $item
                : $factory->from($item),
        );
    }

    /**
     * @return array<TKey, TValue>
     */
    public function items(): array
    {
        return $this->items->all();
    }

    /**
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
     * @param TKey $offset
     *
     * @return bool
     */
    public function offsetExists(mixed $offset): bool
    {
        if (! $this->items instanceof ArrayAccess) {
            throw InvalidDataCollectionOperation::create();
        }

        return $this->items->offsetExists($offset);
    }

    /**
     * @param TKey $offset
     *
     * @return TValue
     */
    public function offsetGet(mixed $offset): mixed
    {
        if (! $this->items instanceof ArrayAccess) {
            throw InvalidDataCollectionOperation::create();
        }

        $data = $this->items->offsetGet($offset);
        $partialDefinitions = $this->getPartialsDefinition();

        if ($data instanceof IncludeableDataContract && ! $partialDefinitions->isEmpty()) {
            $data->getPartialsDefinition()->addResolved(
                $partialDefinitions->resolve($this, consumeTemporary: true),
            );
        }

        return $data;
    }

    /**
     * @param TKey|null $offset
     * @param TValue $value
     *
     * @return void
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        if (! $this->items instanceof ArrayAccess) {
            throw InvalidDataCollectionOperation::create();
        }

        $value = $value instanceof $this->dataClass
            ? $value
            : $this->dataClass::from($value);

        $this->items->offsetSet($offset, $value);
    }

    /**
     * @param TKey $offset
     *
     * @return void
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
