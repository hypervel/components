<?php

declare(strict_types=1);

namespace Hypervel\Data\Concerns;

use Closure;
use Hypervel\Data\Exceptions\PaginatedCollectionIsAlwaysWrapped;

/**
 * @template TKey of array-key
 * @template TValue
 */
trait PaginatedDataCollectable
{
    /** @use BaseDataCollectable<TKey, TValue> */
    use BaseDataCollectable;

    /**
     * @param Closure(TValue, TKey): TValue $through
     */
    public function through(Closure $through): static
    {
        $clone = clone $this;
        $paginator = clone $clone->items;
        $paginator->setCollection(clone $paginator->getCollection());
        $clone->items = $paginator->through($through);

        return $clone;
    }

    /**
     * Get the number of data items on the current page.
     */
    public function count(): int
    {
        return $this->items->count();
    }

    /**
     * Disable wrapping for the collection.
     */
    public function withoutWrapping(): static
    {
        throw PaginatedCollectionIsAlwaysWrapped::create();
    }

    /**
     * Get the underlying items without transforming them.
     *
     * @return iterable<TKey, TValue>
     */
    protected function itemsForIteration(): iterable
    {
        return $this->items->getCollection();
    }
}
