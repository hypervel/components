<?php

declare(strict_types=1);

namespace Hypervel\Data;

use Countable;
use Hypervel\Contracts\Container\Transient;
use Hypervel\Data\Concerns\IncludeableData as IncludeableDataConcern;
use Hypervel\Data\Concerns\PaginatedDataCollectable as PaginatedDataCollectableConcern;
use Hypervel\Data\Concerns\ResponsableData as ResponsableDataConcern;
use Hypervel\Data\Concerns\TransformableData as TransformableDataConcern;
use Hypervel\Data\Concerns\WrappableData as WrappableDataConcern;
use Hypervel\Data\Contracts\BaseData as BaseDataContract;
use Hypervel\Data\Contracts\BaseDataCollectable as BaseDataCollectableContract;
use Hypervel\Data\Contracts\IncludeableData as IncludeableDataContract;
use Hypervel\Data\Contracts\ResponsableData as ResponsableDataContract;
use Hypervel\Data\Contracts\TransformableData as TransformableDataContract;
use Hypervel\Data\Contracts\WrappableData as WrappableDataContract;
use Hypervel\Data\Support\Wrapping\Wrap;
use Hypervel\Data\Support\Wrapping\WrapType;
use Hypervel\Pagination\AbstractPaginator;
use Hypervel\Support\Collection;
use Hypervel\Support\Traits\Macroable;

/**
 * @template TKey of array-key
 * @template TValue of BaseDataContract
 *
 * @implements BaseDataCollectableContract<TKey, TValue>
 */
class PaginatedDataCollection implements BaseDataCollectableContract, TransformableDataContract, IncludeableDataContract, ResponsableDataContract, WrappableDataContract, Countable, Transient
{
    use IncludeableDataConcern;
    use ResponsableDataConcern;
    use TransformableDataConcern;

    /** @use PaginatedDataCollectableConcern<TKey, TValue> */
    use PaginatedDataCollectableConcern, WrappableDataConcern {
        PaginatedDataCollectableConcern::withoutWrapping insteadof WrappableDataConcern;
    }

    use Macroable;

    /** @var AbstractPaginator<TKey, TValue> */
    protected AbstractPaginator $items;

    /**
     * Create a typed paginated data collection.
     *
     * @param class-string<TValue> $dataClass
     * @param AbstractPaginator<TKey, mixed> $items
     */
    public function __construct(
        public readonly string $dataClass,
        AbstractPaginator $items,
    ) {
        $normalized = $this->dataClass::factory()->collectItems($items->getCollection());
        $this->items = (clone $items)->setCollection(
            new Collection($normalized->all()),
        );
        $this->wrap = new Wrap(WrapType::Defined, 'data');
    }

    /**
     * Get the underlying paginator.
     *
     * @return AbstractPaginator<TKey, TValue>
     */
    public function items(): AbstractPaginator
    {
        return $this->items;
    }

    // Persist page items through DataCollection; an item array cannot reconstruct paginator state.
}
