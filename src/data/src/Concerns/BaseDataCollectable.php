<?php

declare(strict_types=1);

namespace Hypervel\Data\Concerns;

use Generator;
use Hypervel\Data\Contracts\BaseData;
use Hypervel\Data\Contracts\IncludeableData;
use Hypervel\Data\Support\Partials\PartialsDefinition;

/**
 * @template TKey of array-key
 * @template TValue of BaseData
 */
trait BaseDataCollectable
{
    /**
     * Get the data class stored by the collection.
     *
     * @return class-string<TValue>
     */
    public function getDataClass(): string
    {
        return $this->dataClass;
    }

    /**
     * Get an iterator for the data items.
     *
     * @return Generator<TKey, TValue>
     */
    public function getIterator(): Generator
    {
        $partialDefinitions = $this->getPartialsDefinition();
        $partials = $partialDefinitions->isEmpty()
            ? null
            : $partialDefinitions->resolve($this, consumeTemporary: true);

        foreach ($this->itemsForIteration() as $key => $item) {
            if ($partials !== null && $item instanceof IncludeableData) {
                $item->getPartialsDefinition()->addResolved($partials);
            }

            yield $key => $item;
        }
    }

    /**
     * Get the current partial definitions.
     */
    abstract public function getPartialsDefinition(): PartialsDefinition;

    /**
     * Get the underlying items without transforming them.
     *
     * @return iterable<TKey, TValue>
     */
    abstract protected function itemsForIteration(): iterable;
}
